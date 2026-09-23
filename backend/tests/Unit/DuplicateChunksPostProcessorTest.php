<?php

namespace Tests\Unit;

use App\Neuron\PostProcessor\DuplicateChunksPostProcessor;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document as Chunk;
use PHPUnit\Framework\TestCase;

class DuplicateChunksPostProcessorTest extends TestCase
{
    public function test_the_best_ranked_copy_stays_and_names_the_documents_of_the_others(): void
    {
        $chunks = [
            $this->chunk('На экране видны полосы.', 'Optix_MAG301RFv1.0_Russian.pdf'),
            $this->chunk('Обновите драйвер видеокарты.', 'Optix_MAG301RFv1.0_Russian.pdf'),
            $this->chunk('На экране видны полосы.', 'MEG342C_QD-OLEDv1.1_Russian.pdf'),
            $this->chunk('На экране видны полосы.', 'Optix_MPG341QR_ru.pdf'),
        ];

        $unique = (new DuplicateChunksPostProcessor)->process(new UserMessage('Что делать, если на MAG301RF полосы?'), $chunks);

        $this->assertSame([$chunks[0], $chunks[1]], $unique);
        $this->assertSame(['MEG342C_QD-OLEDv1.1_Russian.pdf', 'Optix_MPG341QR_ru.pdf'], $unique[0]->metadata['also_in']);
        $this->assertArrayNotHasKey('also_in', $unique[1]->metadata);
    }

    private function chunk(string $content, string $documentName): Chunk
    {
        $chunk = new Chunk($content);
        $chunk->metadata = ['document_name' => $documentName];

        return $chunk;
    }
}
