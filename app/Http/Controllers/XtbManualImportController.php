<?php

namespace App\Http\Controllers;

use App\Application\Portfolio\Xtb\XtbManualImportWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class XtbManualImportController extends Controller
{
    public function index(Request $request): Response
    {
        $active = $this->activePortfolio($request);

        return Inertia::render('Portfolio/XtbImport', [
            'activePortfolio' => $this->portfolio($active),
            'portfolios' => DB::table('portfolio_accounts')->where('user_id', $request->user()->id)->orderBy('broker')->orderBy('account_reference')->get()->map($this->portfolio(...))->all(),
            'batches' => DB::table('portfolio_import_batches')->where('portfolio_account_id', $active->id)->latest('id')->get()->map(fn (object $batch): array => [
                'id' => $batch->id, 'portfolioAccountId' => $batch->portfolio_account_id, 'status' => $batch->status, 'importedAt' => $batch->imported_at,
            ])->all(),
        ]);
    }

    public function upload(Request $request, XtbManualImportWorkflow $workflow): JsonResponse
    {
        $active = $this->activePortfolio($request);
        $request->validate(['workbook' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        $importId = (string) Str::uuid();
        $path = $request->file('workbook')->storeAs('xtb-imports', $importId.'.xlsx', 'local');
        try {
            $analysis = $workflow->analyze(Storage::disk('local')->path($path));
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($active->broker !== 'xtb' || $active->account_reference !== $analysis->accountReference) {
            Storage::disk('local')->delete($path);
            throw new InvalidArgumentException('The XTB workbook does not belong to the active portfolio.');
        }
        $statusHistory = ['UPLOADED', 'ANALYZING', 'READY_FOR_CONFIRMATION'];
        $request->session()->put($this->key($importId), compact('path', 'statusHistory') + ['portfolioAccountId' => $active->id]);

        return response()->json(['importId' => $importId, 'status' => 'READY_FOR_CONFIRMATION', 'statusHistory' => $statusHistory, 'summary' => $analysis->summary(), 'activePortfolio' => $this->portfolio($active)], 201);
    }

    public function confirm(Request $request, string $importId, XtbManualImportWorkflow $workflow): JsonResponse
    {
        $active = $this->activePortfolio($request);
        $draft = $request->session()->get($this->key($importId));
        if (! is_array($draft) || ! isset($draft['path'])) {
            throw new InvalidArgumentException('The temporary XTB import is unavailable. Upload it again.');
        }
        if (($draft['portfolioAccountId'] ?? null) !== $active->id) {
            $this->discardDraft($request, $importId, $draft);

            throw new InvalidArgumentException('The temporary XTB import is unavailable. Upload it again.');
        }
        $statusHistory = $draft['statusHistory'] ?? ['UPLOADED', 'ANALYZING', 'READY_FOR_CONFIRMATION'];
        $validator = Validator::make($request->all(), ['mappings' => ['present', 'array'], 'mappings.*' => ['string', 'max:255']]);
        if ($validator->fails()) {
            return response()->json(['status' => 'READY_FOR_CONFIRMATION', 'statusHistory' => $statusHistory, 'errors' => $validator->errors()], 422);
        }
        $request->session()->forget($this->key($importId));
        $statusHistory = [...$statusHistory, 'CONFIRMED', 'PROCESSING'];
        try {
            $result = $workflow->confirm($draft['path'], $request->input('mappings', []));
        } catch (\Throwable $exception) {
            return response()->json(['status' => 'FAILED', 'statusHistory' => [...$statusHistory, 'FAILED'], 'errors' => ['workbook' => [$exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The XTB workbook could not be processed.']]], $exception instanceof InvalidArgumentException ? 422 : 500);
        }
        $result['statusHistory'] = [...$statusHistory, $result['status']];

        return response()->json($result);
    }

    public function reprocess(Request $request, int $batchId, XtbManualImportWorkflow $workflow): JsonResponse
    {
        $this->batch($request, $batchId);
        $request->validate(['mappings' => ['present', 'array'], 'mappings.*' => ['string', 'max:255']]);

        return response()->json($workflow->reprocess($batchId, $request->input('mappings', [])));
    }

    public function destroy(Request $request, int $batchId, XtbManualImportWorkflow $workflow): JsonResponse
    {
        $this->batch($request, $batchId);
        $workflow->delete($batchId);

        return response()->json(['status' => 'DELETED']);
    }

    public function select(Request $request): JsonResponse
    {
        $validated = $request->validate(['portfolioId' => ['required', 'integer']]);
        $account = DB::table('portfolio_accounts')->where('id', $validated['portfolioId'])->where('user_id', $request->user()->id)->first();
        abort_unless($account, 404);
        DB::transaction(function () use ($request, $account): void {
            DB::table('portfolio_accounts')->where('user_id', $request->user()->id)->update(['is_active' => false]);
            DB::table('portfolio_accounts')->where('id', $account->id)->update(['is_active' => true]);
        });

        return response()->json(['activePortfolio' => $this->portfolio($account)]);
    }

    private function activePortfolio(Request $request): object
    {
        $account = DB::table('portfolio_accounts')->where('user_id', $request->user()->id)->where('is_active', true)->first();
        abort_unless($account, 422, 'Select an active portfolio first.');

        return $account;
    }

    private function batch(Request $request, int $batchId): object
    {
        $batch = DB::table('portfolio_import_batches as batches')->join('portfolio_accounts as accounts', 'accounts.id', '=', 'batches.portfolio_account_id')->where('batches.id', $batchId)->where('accounts.user_id', $request->user()->id)->where('accounts.is_active', true)->select('batches.*')->first();
        abort_unless($batch, 404);

        return $batch;
    }

    private function portfolio(object $account): array
    {
        return ['id' => $account->id, 'broker' => $account->broker, 'accountReference' => $account->account_reference, 'isActive' => (bool) $account->is_active];
    }

    private function key(string $importId): string
    {
        return 'xtb_import_draft:'.$importId;
    }

    /** @param array{path: mixed} $draft */
    private function discardDraft(Request $request, string $importId, array $draft): void
    {
        $path = $draft['path'];
        if (is_string($path) && preg_match('/\Axtb-imports\/[0-9a-f-]{36}\.xlsx\z/iD', $path) === 1) {
            Storage::disk('local')->delete($path);
        }
        $request->session()->forget($this->key($importId));
    }
}
