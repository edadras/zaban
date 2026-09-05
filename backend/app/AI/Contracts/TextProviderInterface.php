<?php

namespace App\AI\Contracts;

use App\AI\Support\TextRequest;
use App\AI\Support\TextResult;

interface TextProviderInterface extends AiProviderInterface
{
    public function generateText(TextRequest $request): TextResult;
}
