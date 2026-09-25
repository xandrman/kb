<?php

namespace App\Neuron\PostProcessor;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

/**
 * Same text goes to the model once, as its best-ranked copy; the documents of the other copies stay on it.
 *
 * Руководства одной серии повторяют разделы слово в слово. Склеивать нужно после реранкера: он оценивает копии вместе
 * с документом, и первой остаётся копия из документа модели, названной в вопросе.
 */
class DuplicateChunksPostProcessor implements PostProcessorInterface
{
    public function process(Message $question, array $documents): array
    {
        $unique = [];

        foreach ($documents as $chunk) {
            $hash = md5($chunk->getContent());

            if (! isset($unique[$hash])) {
                $unique[$hash] = $chunk;

                continue;
            }

            $kept = $unique[$hash];
            $name = $chunk->metadata['document_name'] ?? null;

            if ($name !== null && $name !== ($kept->metadata['document_name'] ?? null)) {
                $kept->addMetadata('also_in', array_values(array_unique([...$kept->metadata['also_in'] ?? [], $name])));
            }
        }

        return array_values($unique);
    }
}
