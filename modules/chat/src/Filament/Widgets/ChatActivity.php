<?php

namespace Modules\Chat\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Modules\Chat\Insights\ChatInsights;

class ChatActivity extends ChartWidget
{
    /**
     * Filament's Dashboard renders every discovered widget, so without this
     * these five would also turn up on the panel's front page -- uninvited, and
     * repeating the same scan of the message table a second time.
     * `ChatInsights::getWidgets()` names them explicitly instead.
     */
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    // Left unbounded the canvas grows to fill the page and pushes the tables
    // that actually need reading below the fold.
    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return __('Activity');
    }

    public function getDescription(): string
    {
        return __('Questions asked, replies streamed, and tools called each day.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $activity = ChatInsights::make()->dailyActivity();

        return [
            'labels' => $activity['labels'],
            'datasets' => [
                [
                    'label' => __('Questions'),
                    'data' => $activity['questions'],
                    'borderColor' => 'rgb(139, 92, 246)',
                    'backgroundColor' => 'rgba(139, 92, 246, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => __('Replies'),
                    'data' => $activity['replies'],
                    'borderColor' => 'rgb(56, 189, 248)',
                    'backgroundColor' => 'rgba(56, 189, 248, 0.10)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    // Tool calls track replies closely when the assistant is
                    // doing its job; a reply line that pulls away from this one
                    // means it started answering from memory.
                    'label' => __('Tool calls'),
                    'data' => $activity['tool_calls'],
                    'borderColor' => 'rgb(251, 191, 36)',
                    'backgroundColor' => 'rgba(251, 191, 36, 0.10)',
                    'fill' => false,
                    'borderDash' => [4, 4],
                    'tension' => 0.3,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                // Whole messages only; a y-axis offering "1.5 questions" is noise.
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
