<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\Subject;
use App\Services\MediaWiki\WikiClient;
use App\Services\MediaWiki\WikiProblem;
use Illuminate\Support\Facades\Log;

final class SubjectResolver
{
    public function resolve(?Subject $subject): ?Subject
    {
        if ($subject === null || ! $subject->needsCentralId()) {
            return $subject;
        }

        $name = $subject->wikiName();

        if ($name === null || trim($name) === '') {
            return $subject;
        }

        try {
            $found = WikiClient::make()->lookupUser($name);
        } catch (WikiProblem $e) {
            Log::warning('Could not ask the wiki who a subject is', [
                'subject' => $subject->username,
                'error' => $e->getMessage(),
            ]);

            return $subject;
        }

        if ($found === null || empty($found['central_id'])) {
            return $subject;
        }

        $subject->attachCentralId((int) $found['central_id']);

        return $subject;
    }
}
