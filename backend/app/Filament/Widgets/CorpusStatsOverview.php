<?php

namespace App\Filament\Widgets;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Corpus statistics on the admin dashboard (FR-1), refreshed with the processing queue.
 */
class CorpusStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Корпус';

    protected function getStats(): array
    {
        $inProgress = [DocumentStatus::Pending, DocumentStatus::Extracting, DocumentStatus::Extracted, DocumentStatus::Indexed];

        // Один агрегирующий запрос: виджет опрашивается каждые 5 с; строка агрегатов есть и у пустой таблицы
        /** @var \stdClass $corpus */
        $corpus = Document::query()
            ->where('status', '!=', DocumentStatus::Duplicate)
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as processed', [DocumentStatus::Processed->value])
            ->selectRaw(
                'sum(case when status in ('.implode(', ', array_fill(0, count($inProgress), '?')).') then 1 else 0 end) as in_progress',
                array_map(fn (DocumentStatus $status): string => $status->value, $inProgress),
            )
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [DocumentStatus::Failed->value])
            ->selectRaw('coalesce(sum(size), 0) as size')
            ->selectRaw('coalesce(sum(personal_data_count), 0) as personal_data')
            ->selectRaw(
                'avg(case when status = ? then coalesce(docling_processing_time, 0) + coalesce(indexing_time, 0) + coalesce(graph_time, 0) end) as processing_time',
                [DocumentStatus::Processed->value],
            )
            ->first();

        return [
            Stat::make('Документов', self::count($corpus->total))
                ->description('Без дубликатов'),
            Stat::make('Обработано', self::count($corpus->processed))
                ->color('success'),
            Stat::make('В очереди и обработке', self::count($corpus->in_progress))
                ->color('info'),
            Stat::make('С ошибкой', self::count($corpus->failed))
                ->color((int) $corpus->failed > 0 ? 'danger' : 'gray'),
            Stat::make('Объём файлов', self::megabytes((int) $corpus->size)),
            Stat::make('Замаскировано ПДн', self::count($corpus->personal_data))
                ->description('Фрагментов до индексации'),
            Stat::make('Среднее время обработки', self::duration($corpus->processing_time))
                ->description('Норматив — не более 30 мин на документ'),
        ];
    }

    // В образе Alpine у ICU только данные en: числа форматируются без intl, с русскими разделителями

    private static function count(mixed $value): string
    {
        return number_format((int) $value, thousands_separator: ' ');
    }

    private static function megabytes(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 1, ',', ' ').' МБ';
    }

    private static function duration(mixed $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $seconds = (float) $seconds;

        return $seconds < 60
            ? number_format($seconds, 1, ',', ' ').' с'
            : number_format($seconds / 60, 1, ',', ' ').' мин';
    }
}
