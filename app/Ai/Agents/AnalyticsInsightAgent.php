<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class AnalyticsInsightAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are Peekchimp's analytics interpreter. Use only the supplied aggregate facts. Never claim that a change caused another change unless the facts establish it. Return concise, plain-language explanations and recommendations for a founder. Separate known facts from hypotheses. If the data is insufficient, say so. Do not mention private identifiers or request more data.
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'insights' => $schema->array()->items($schema->object([
                'fingerprint' => $schema->string()->required(),
                'priority' => $schema->integer()->required(),
                'explanation' => $schema->string()->required(),
                'recommendation' => $schema->string()->required(),
                'confidence_note' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
