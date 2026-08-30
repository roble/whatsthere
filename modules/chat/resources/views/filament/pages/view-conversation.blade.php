<x-filament-panels::page>
    <div class="space-y-4">
        @foreach ($this->messages() as $message)
            @php
                $isUser = $message->role === 'user';
                $usage = $this->usageFor($message);
                $steps = $this->stepsFor($message);
            @endphp

            <x-filament::section
                :icon="$isUser ? 'heroicon-o-user' : 'heroicon-o-sparkles'"
                :icon-color="$isUser ? 'gray' : 'primary'"
                collapsible
                :collapsed="false"
            >
                <x-slot name="heading">
                    {{ $isUser ? __('Question') : __('Reply') }}
                </x-slot>

                <x-slot name="description">
                    {{ $message->created_at?->toDayDateTimeString() }}
                    @if ($model = data_get($message->meta, 'model'))
                        &middot; {{ $model }}
                    @endif
                </x-slot>

                {{-- The route of thought, rebuilt from what was stored. --}}
                @if (filled($steps))
                    <div class="mb-4 space-y-2">
                        @foreach ($steps as $step)
                            <div @class([
                                'rounded-lg border p-3 text-sm',
                                'border-success-300 bg-success-50 dark:border-success-700/50 dark:bg-success-500/10' => $step['succeeded'],
                                'border-warning-300 bg-warning-50 dark:border-warning-700/50 dark:bg-warning-500/10' => ! $step['succeeded'],
                            ])>
                                <div class="flex items-center gap-2">
                                    <x-filament::icon
                                        :icon="$step['succeeded'] ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'"
                                        @class([
                                            'h-4 w-4 shrink-0',
                                            'text-success-600 dark:text-success-400' => $step['succeeded'],
                                            'text-warning-600 dark:text-warning-400' => ! $step['succeeded'],
                                        ])
                                    />
                                    <span class="font-medium text-gray-950 dark:text-white">
                                        {{ str($step['name'])->replace('_', ' ')->title() }}
                                    </span>
                                    <code class="text-xs text-gray-600 dark:text-gray-400">{{ $step['input'] }}</code>
                                </div>

                                @if ($step['reasoning'])
                                    <p class="mt-2 whitespace-pre-line text-xs italic text-gray-600 dark:text-gray-400">
                                        {{ $step['reasoning'] }}
                                    </p>
                                @endif

                                <p class="mt-2 break-all font-mono text-xs text-gray-500 dark:text-gray-500">
                                    {{ str($step['result'])->limit(300) }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Escaped at render, so raw HTML from the model is shown,
                     never executed. --}}
                <div class="prose prose-sm max-w-none dark:prose-invert">
                    {!! $this->bodyFor($message) !!}
                </div>

                {{-- Only replies are billed, so only replies get a gutter. --}}
                @if ($usage['completion'] > 0)
                    <div
                        class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-200 pt-3 dark:border-white/10"
                    >
                        {{-- Coloured and icon-led so it reads as the heading for
                             the row rather than a fifth measurement. --}}
                        <x-filament::badge
                            color="info"
                            size="sm"
                            icon="heroicon-m-cpu-chip"
                        >
                            {{ __('Tokens') }}
                        </x-filament::badge>

                        @foreach ([
                            __('in') => $usage['prompt'],
                            __('out') => $usage['completion'],
                            __('thinking') => $usage['reasoning'],
                            __('cached') => $usage['cached'],
                        ] as $label => $count)
                            @if ($count > 0)
                                <x-filament::badge color="gray" size="sm">
                                    {{ $label }} {{ number_format($count) }}
                                </x-filament::badge>
                            @endif
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
