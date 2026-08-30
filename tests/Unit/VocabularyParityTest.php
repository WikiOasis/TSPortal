<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\SafetyCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class VocabularyParityTest extends TestCase
{
    /** @return array<string, string> */
    private function fromConfig(): array
    {
        $config = require __DIR__.'/../../config/categories.php';

        return array_map(
            fn (array $reason) => $reason['label'],
            $config['action_reasons'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function fromJavaScript(): array
    {
        $source = (string) file_get_contents(__DIR__.'/../../resources/js/lib/format.js');

        $start = strpos($source, 'export const ACTION_REASONS = [');
        $this->assertNotFalse($start, 'format.js no longer exports ACTION_REASONS.');

        $end = strpos($source, '];', $start);
        $block = substr($source, $start, $end - $start);

        preg_match_all(
            "/\{\s*value:\s*'([^']+)',\s*label:\s*'([^']+)'\s*\}/",
            $block,
            $matches,
            PREG_SET_ORDER,
        );

        $reasons = [];
        foreach ($matches as $match) {
            $reasons[$match[1]] = $match[2];
        }

        return $reasons;
    }

    #[Test]
    public function the_form_offers_exactly_what_the_portal_will_accept(): void
    {
        $config = $this->fromConfig();
        $form = $this->fromJavaScript();

        $this->assertSame(
            array_keys($config),
            array_keys($form),
            'config/categories.php and resources/js/lib/format.js disagree about the action reasons. '
            .'A reason in one and not the other is either a category nothing can be filed under, '
            .'or a dropdown option that fails validation at the end of issuing a suspension.',
        );
    }

    #[Test]
    public function both_lists_use_the_same_wording(): void
    {
        $this->assertSame($this->fromConfig(), $this->fromJavaScript());
    }

    #[Test]
    public function the_panel_offers_exactly_the_appeal_outcomes_the_portal_accepts(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../resources/js/lib/format.js');

        $start = strpos($source, 'export const APPEAL_OUTCOMES = [');
        $this->assertNotFalse($start, 'format.js no longer exports APPEAL_OUTCOMES.');

        $block = substr($source, $start, (int) strpos($source, '];', $start) - $start);

        preg_match_all("/value:\s*'([^']+)'/", $block, $matches);

        $this->assertSame(
            SafetyCase::APPEAL_OUTCOMES,
            $matches[1],
            'SafetyCase::APPEAL_OUTCOMES and format.js disagree about how an appeal can end.',
        );
    }
}
