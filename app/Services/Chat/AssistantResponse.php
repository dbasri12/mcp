<?php

namespace App\Services\Chat;

final class AssistantResponse
{
    /**
     * @param  array<int, array{fileName:string,pageNumber:int,text:string}>  $references
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $intent,
        public readonly string $text,
        public readonly array $references = [],
        public readonly array $meta = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'mode' => $this->mode,
            'intent' => $this->intent,
            'text' => $this->text,
            'references' => $this->references,
        ];

        if ($this->meta !== []) {
            $payload['meta'] = $this->meta;
        }

        return $payload;
    }
}
