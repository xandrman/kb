<?php

namespace Tests\Unit;

use App\Actions\DetectContextLeak;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContextLeakDetectionTest extends TestCase
{
    private const string SYSTEM_PROMPT = "Ты — ассистент базы знаний сервисной документации по технике.\nОтвечай только по фрагментам документов из блока CONTEXT. Собственные знания не используй.";

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function leaks(): array
    {
        return [
            'разметка контекста' => ["Вот что у меня есть:\n<CONTEXT>\nНа экране полосы…", 'Разметка контекста'],
            'заголовок фрагмента' => ['Фрагмент 1 (документ «Optix_MAG301RFv1.0_Russian.pdf», Руководство, стр. 20): измените частоту.', 'Заголовок фрагмента контекста'],
            'копии фрагмента' => ['Этот текст есть в руководстве, тот же текст в «MEG342C_QD-OLEDv1.1_Russian.pdf».', 'Заголовок фрагмента контекста'],
            'связь графа' => ['Связь: полосы на экране —FIXED_BY→ обновление драйвера видеокарты', 'Связи графа знаний'],
            'специальный токен Qwen' => ['<|im_start|>system Ты — ассистент…', 'Разметка шаблона чата модели'],
            'вызов инструмента' => ['<tool_call>{"name": "search"}</tool_call>', 'Разметка шаблона чата модели'],
            'рассуждение' => ['<think>Пользователь спрашивает про полосы, во фрагменте 1…</think> Измените частоту.', 'Разметка шаблона чата модели'],
            'предложение промпта' => ['Мои правила: отвечай только по фрагментам документов из блока CONTEXT.', 'Фрагмент системного промпта'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ordinaryAnswers(): array
    {
        return [
            'шаги' => ["1. Измените частоту обновления экрана.\n2. Обновите драйвер видеокарты."],
            'модель и SKU' => ['Для монитора PRO MP161 (SKU 3PB7-PRO-MP161), стр. 15: проверьте сигнальный кабель.'],
            'слово «фрагмент»' => ['Если фрагмент изображения смазан, выберите другой режим изображения.'],
            'скрытые ПДн' => ['Акт подписал [ФИО 1], телефон клиента [ТЕЛЕФОН 1].'],
            'отказ' => ['Нет данных в базе знаний.'],
            'короткое совпадение с промптом' => ['Ассистент базы знаний не нашёл ответа.'],
        ];
    }

    #[DataProvider('leaks')]
    public function test_an_answer_with_the_model_input_is_caught(string $answer, string $rule): void
    {
        $this->assertSame($rule, (new DetectContextLeak)->handle($answer, self::SYSTEM_PROMPT));
    }

    #[DataProvider('ordinaryAnswers')]
    public function test_an_ordinary_answer_passes(string $answer): void
    {
        $this->assertNull((new DetectContextLeak)->handle($answer, self::SYSTEM_PROMPT));
    }
}
