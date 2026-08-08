<?php

namespace App\Http\Controllers;

use App\Application\Portfolio\Xtb\XtbManualImportWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class XtbManualImportController extends Controller
{
    public function upload(Request $request, XtbManualImportWorkflow $workflow): JsonResponse
    {
        $request->validate(['workbook' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        $importId = (string) Str::uuid();
        $path = $request->file('workbook')->storeAs('xtb-imports', $importId.'.xlsx', 'local');
        try {
            $analysis = $workflow->analyze(Storage::disk('local')->path($path));
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        $statusHistory = ['UPLOADED', 'ANALYZING', 'READY_FOR_CONFIRMATION'];
        $request->session()->put($this->key($importId), compact('path', 'statusHistory'));

        return response()->json(['importId' => $importId, 'status' => 'READY_FOR_CONFIRMATION', 'statusHistory' => $statusHistory, 'summary' => $analysis->summary()], 201);
    }

    public function confirm(Request $request, string $importId, XtbManualImportWorkflow $workflow): JsonResponse
    {
        $draft = $request->session()->get($this->key($importId));
        if (! is_array($draft) || ! isset($draft['path'])) {
            throw new InvalidArgumentException('The temporary XTB import is unavailable. Upload it again.');
        }

        $statusHistory = $draft['statusHistory'] ?? ['UPLOADED', 'ANALYZING', 'READY_FOR_CONFIRMATION'];
        $validator = Validator::make($request->all(), [
            'mappings' => ['present', 'array'],
            'mappings.*' => ['string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'READY_FOR_CONFIRMATION',
                'statusHistory' => $statusHistory,
                'errors' => $validator->errors(),
            ], 422);
        }

        $request->session()->forget($this->key($importId));
        $statusHistory = [...$statusHistory, 'CONFIRMED', 'PROCESSING'];
        try {
            $result = $workflow->confirm($draft['path'], $request->input('mappings', []));
        } catch (\Throwable $exception) {
            $isInvalidWorkbook = $exception instanceof InvalidArgumentException;

            return response()->json([
                'status' => 'FAILED',
                'statusHistory' => [...$statusHistory, 'FAILED'],
                'errors' => ['workbook' => [$isInvalidWorkbook ? $exception->getMessage() : 'The XTB workbook could not be processed.']],
            ], $isInvalidWorkbook ? 422 : 500);
        }
        $result['statusHistory'] = [...$statusHistory, $result['status']];

        return response()->json($result);
    }

    public function reprocess(Request $request, int $batchId, XtbManualImportWorkflow $workflow): JsonResponse
    {
        $request->validate(['mappings' => ['present', 'array'], 'mappings.*' => ['string', 'max:255']]);

        return response()->json($workflow->reprocess($batchId, $request->input('mappings', [])));
    }

    public function destroy(int $batchId, XtbManualImportWorkflow $workflow): JsonResponse
    {
        $workflow->delete($batchId);

        return response()->json(['status' => 'DELETED']);
    }

    private function key(string $importId): string
    {
        return 'xtb_import_draft:'.$importId;
    }
}
