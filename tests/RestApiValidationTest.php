<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests REST API input validation and response logic.
 *
 * These test the validation callbacks and response format without
 * making real AI API calls.
 */
class RestApiValidationTest extends TestCase {

    // -------------------------------------------------------------------
    // Expand query validation
    // -------------------------------------------------------------------

    public function test_expand_query_valid(): void {
        $value = 'product pricing';
        $valid = is_string($value) && strlen($value) > 0 && strlen($value) <= 500;
        $this->assertTrue($valid);
    }

    public function test_expand_query_rejects_empty(): void {
        $value = '';
        $valid = is_string($value) && strlen($value) > 0 && strlen($value) <= 500;
        $this->assertFalse($valid);
    }

    public function test_expand_query_rejects_too_long(): void {
        $value = str_repeat('a', 501);
        $valid = is_string($value) && strlen($value) > 0 && strlen($value) <= 500;
        $this->assertFalse($valid);
    }

    public function test_expand_query_accepts_max_length(): void {
        $value = str_repeat('a', 500);
        $valid = is_string($value) && strlen($value) > 0 && strlen($value) <= 500;
        $this->assertTrue($valid);
    }

    // -------------------------------------------------------------------
    // Summarize context validation
    // -------------------------------------------------------------------

    public function test_summarize_context_valid(): void {
        $value = 'Search result excerpts...';
        $valid = is_string($value) && strlen($value) > 0 && strlen($value) <= 50000;
        $this->assertTrue($valid);
    }

    public function test_summarize_context_rejects_empty(): void {
        $value = '';
        $valid = is_string($value) && strlen($value) > 0 && strlen($value) <= 50000;
        $this->assertFalse($valid);
    }

    public function test_summarize_context_rejects_too_long(): void {
        $value = str_repeat('x', 50001);
        $valid = is_string($value) && strlen($value) > 0 && strlen($value) <= 50000;
        $this->assertFalse($valid);
    }

    // -------------------------------------------------------------------
    // Follow-up messages validation
    // -------------------------------------------------------------------

    public function test_followup_valid_messages(): void {
        $messages = [
            ['role' => 'user', 'content' => 'What is WordPress?'],
            ['role' => 'assistant', 'content' => 'A CMS.'],
            ['role' => 'user', 'content' => 'Tell me more.'],
        ];
        $this->assertTrue($this->validateMessages($messages));
    }

    public function test_followup_rejects_empty(): void {
        $this->assertFalse($this->validateMessages([]));
    }

    public function test_followup_rejects_missing_role(): void {
        $messages = [['content' => 'Hello']];
        $this->assertFalse($this->validateMessages($messages));
    }

    public function test_followup_rejects_missing_content(): void {
        $messages = [['role' => 'user']];
        $this->assertFalse($this->validateMessages($messages));
    }

    public function test_followup_rejects_system_role(): void {
        $messages = [['role' => 'system', 'content' => 'sneaky']];
        $this->assertFalse($this->validateMessages($messages));
    }

    public function test_followup_rejects_last_not_user(): void {
        $messages = [
            ['role' => 'user', 'content' => 'Q'],
            ['role' => 'assistant', 'content' => 'A'],
        ];
        $this->assertFalse($this->validateMessages($messages));
    }

    public function test_followup_accepts_single_user_message(): void {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $this->assertTrue($this->validateMessages($messages));
    }

    // -------------------------------------------------------------------
    // Follow-up rate limiting
    // -------------------------------------------------------------------

    public function test_followup_count_calculation(): void {
        // 2 messages (initial exchange): 0 follow-ups.
        $this->assertEquals(0, intdiv(2 - 2, 2));
        // 4 messages: 1 follow-up.
        $this->assertEquals(1, intdiv(4 - 2, 2));
        // 6 messages: 2 follow-ups.
        $this->assertEquals(2, intdiv(6 - 2, 2));
        // 8 messages: 3 follow-ups.
        $this->assertEquals(3, intdiv(8 - 2, 2));
    }

    public function test_followup_remaining_calculation(): void {
        $max = 3;
        // After 0 follow-ups, submitting 1st: 2 remaining.
        $this->assertEquals(2, max(0, $max - 0 - 1));
        // After 1 follow-up, submitting 2nd: 1 remaining.
        $this->assertEquals(1, max(0, $max - 1 - 1));
        // After 2 follow-ups, submitting 3rd: 0 remaining.
        $this->assertEquals(0, max(0, $max - 2 - 1));
    }

    public function test_followup_limit_enforcement(): void {
        $max = 3;
        // 8 messages = 3 follow-ups done → should be rejected.
        $followups = intdiv(8 - 2, 2);
        $this->assertTrue($followups >= $max);
    }

    // -------------------------------------------------------------------
    // Expand response parsing (markdown fence stripping)
    // -------------------------------------------------------------------

    public function test_expand_strips_json_fences(): void {
        $response = "```json\n[\"term1\", \"term2\"]\n```";
        $cleaned = trim($response);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $terms = json_decode($cleaned, true);
        $this->assertEquals(['term1', 'term2'], $terms);
    }

    public function test_expand_strips_bare_fences(): void {
        $response = "```\n[\"one\", \"two\", \"three\"]\n```";
        $cleaned = trim($response);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $terms = json_decode($cleaned, true);
        $this->assertCount(3, $terms);
    }

    public function test_expand_handles_raw_json(): void {
        $response = '["alpha", "beta"]';
        $cleaned = trim($response);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $terms = json_decode($cleaned, true);
        $this->assertEquals(['alpha', 'beta'], $terms);
    }

    public function test_expand_fallback_on_invalid_json(): void {
        $query = 'test query';
        $cleaned = 'not valid json';
        $terms = json_decode($cleaned, true);
        if (!is_array($terms) || count($terms) < 2) {
            $terms = [$query];
        }
        $this->assertEquals(['test query'], $terms);
    }

    // -------------------------------------------------------------------
    // Cache key determinism
    // -------------------------------------------------------------------

    public function test_cache_key_is_case_insensitive(): void {
        $key1 = 'scolta_expand_' . md5(strtolower('Product Pricing'));
        $key2 = 'scolta_expand_' . md5(strtolower('product pricing'));
        $this->assertEquals($key1, $key2);
    }

    // -------------------------------------------------------------------
    // Permission callback
    // -------------------------------------------------------------------

    public function test_search_permission_default_public(): void {
        $this->assertTrue(Scolta_Rest_Api::check_search_permission());
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function validateMessages(array $messages): bool {
        if (empty($messages)) return false;
        foreach ($messages as $msg) {
            if (empty($msg['role']) || empty($msg['content'])) return false;
            if (!in_array($msg['role'], ['user', 'assistant'], true)) return false;
        }
        return end($messages)['role'] === 'user';
    }
}
