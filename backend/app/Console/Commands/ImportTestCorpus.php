<?php

namespace App\Console\Commands;

use App\Actions\StoreUploadedDocument;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('testdata:import
    {--uploader= : E-mail пользователя, от имени которого загружается корпус}
    {--registry= : Реестр публичного корпуса; по умолчанию testdata/registry.json}
    {--manifest= : Манифест синтетических актов; по умолчанию testdata/synthetic/manifest.json}')]
#[Description('Загрузить пилотный корпус с метаданными, как загрузка через админ-панель (ТЗ 4.5, 9.3)')]
class ImportTestCorpus extends Command
{
    public function handle(StoreUploadedDocument $storeDocument): int
    {
        $uploader = User::where('email', $this->option('uploader'))->first()
            ?? throw new RuntimeException("Нет пользователя с e-mail «{$this->option('uploader')}»: укажите --uploader.");

        $registryPath = $this->option('registry') ?: base_path('../testdata/registry.json');
        $manifestPath = $this->option('manifest') ?: base_path('../testdata/synthetic/manifest.json');

        $entries = [
            ...$this->entriesFrom($registryPath, fn (array $entry): bool => $entry['in_corpus']),
            ...$this->entriesFrom($manifestPath, fn (array $entry): bool => true),
        ];
        $documentTypes = $this->documentTypes(array_unique(array_column($entries, 'document_type')));

        $imported = 0;
        foreach ($entries as $entry) {
            // Повторный запуск не плодит дубликаты: уже загруженный файл пропускается, а не регистрируется заново
            if (Document::where('digest', hash_file('sha256', $entry['path']))->exists()) {
                $this->line("уже загружен  {$entry['filename']}");

                continue;
            }

            $storeDocument->handle(new UploadedFile($entry['path'], $entry['filename']), [
                'sku' => $entry['sku'],
                'document_type_id' => $documentTypes[$entry['document_type']],
                'access_level' => $entry['access_level'],
                'owner_department' => $entry['owner_department'],
                'document_date' => $entry['document_date'],
            ], $uploader);
            $imported++;
            $this->line("в очереди     {$entry['filename']}");
        }

        $this->info("Поставлено в очередь: {$imported} из ".count($entries));

        return self::SUCCESS;
    }

    /**
     * Corpus entries of one list, each with the path of its file: files lie next to the list.
     *
     * @param  callable(array<string, mixed>): bool  $isInCorpus
     * @return list<array<string, mixed>>
     */
    private function entriesFrom(string $listPath, callable $isInCorpus): array
    {
        $entries = array_values(array_filter(
            json_decode(File::get($listPath), true, flags: JSON_THROW_ON_ERROR),
            $isInCorpus,
        ));

        foreach ($entries as &$entry) {
            $entry['path'] = dirname($listPath).'/'.$entry['filename'];
            if (! File::exists($entry['path'])) {
                throw new RuntimeException("Файл из {$listPath} не найден: {$entry['path']}");
            }
        }

        return $entries;
    }

    /**
     * Ids of the active document types the corpus names; an unknown or disabled type stops the import before any upload.
     *
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function documentTypes(array $names): array
    {
        $ids = DocumentType::active()->whereIn('name', $names)->pluck('id', 'name')->all();

        $missing = array_diff($names, array_keys($ids));
        if ($missing !== []) {
            throw new RuntimeException('Нет активных типов документов: '.implode(', ', $missing).'. Заведите их в админ-панели.');
        }

        return $ids;
    }
}
