<?php

// Deterministic fixture for the HTTP and browser e2e tests. Served from the repo root:
//   php -S 127.0.0.1:8123
require __DIR__ . '/../../../vendor/autoload.php';

use FaridM\StepChat\Flow;
use FaridM\StepChat\Http\ChatEndpoint;
use FaridM\StepChat\Http\FlowRegistry;
use FaridM\StepChat\Steps;
use FaridM\StepChat\Support\NativeSessionStore;

$flows = (new FlowRegistry())->register('lead', static function (): Flow {
    return Flow::make()
        ->add(Steps::text('name', 'Your name?'))
        ->add(Steps::email('email', 'Your email?'))
        ->add(Steps::choice('plan', 'Which plan?', ['starter' => 'Starter', 'pro' => 'Pro']))
        ->add(Steps::multiChoice('topics', 'Topics?', ['api' => 'API', 'pricing' => 'Pricing', 'support' => 'Support']))
        ->add(Steps::phone('phone', 'Phone (optional)')->optional())
        ->add(Steps::text('note', 'Anything else?', ['multiline' => true])->optional())
        ->onComplete(static function (array $answers): void {
            file_put_contents(
                sys_get_temp_dir() . '/stepchat-e2e-leads.jsonl',
                json_encode($answers, JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );
        });
});

(new ChatEndpoint($flows, new NativeSessionStore()))->serve();
