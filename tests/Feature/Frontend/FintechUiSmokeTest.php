<?php

it('ships the shared authenticated fintech layout and Tailwind theme', function (): void {
    $layout = resource_path('js/Components/AppLayout.vue');
    $theme = resource_path('css/app.css');

    expect($layout)->toBeFile()
        ->and(file_get_contents($layout))->toContain('router.post(\'/logout\'')
        ->and(file_get_contents($layout))->toContain('valuationHref')
        ->and(file_get_contents($layout))->toContain('Dashboard / Valuation')
        ->and(file_get_contents($theme))->toContain('@theme')
        ->and(file_get_contents($theme))->toContain('--color-brand-600')
        ->and(file_get_contents($theme))->toContain('--color-neutral-500')
        ->and(file_get_contents($theme))->toContain('--spacing-18')
        ->and(file_get_contents($theme))->toContain('--radius-card');
});

it('renders portfolio pages inside the shared authenticated layout', function (): void {
    foreach ([
        'Portfolio/Onboarding.vue',
        'Portfolio/ValuationDashboard.vue',
        'Portfolio/XtbImport.vue',
    ] as $page) {
        expect(file_get_contents(resource_path("js/Pages/{$page}")))->toContain('@/Components/AppLayout.vue');
    }
});

it('bounds responsive valuation chart canvases inside finite positioned wrappers', function (): void {
    $dashboard = file_get_contents(resource_path('js/Pages/Portfolio/ValuationDashboard.vue'));

    expect($dashboard)
        ->toContain('chart-canvas-wrapper')
        ->toContain('position: relative')
        ->toContain('width: 100%')
        ->toContain('height: 17.5rem')
        ->toContain('min-height: 0')
        ->toContain('<div v-if="availableHistory.length" class="chart-canvas-wrapper mt-5">')
        ->toContain('v-else-if="assetClassChartItems.length"')
        ->not->toContain('h-70');
});
