<?php

namespace FaridM\StepChat\Tests\Http;

use FaridM\StepChat\Flow;
use FaridM\StepChat\Http\ChatEndpoint;
use FaridM\StepChat\Http\FlowRegistry;
use FaridM\StepChat\Steps;
use FaridM\StepChat\Support\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

final class ChatEndpointContractTest extends TestCase
{
    private function endpoint(): ChatEndpoint
    {
        $registry = (new FlowRegistry())->register('lead', static function (): Flow {
            return Flow::make()
                ->add(Steps::text('name', 'Name'))
                ->add(Steps::email('email', 'Email'));
        });

        return new ChatEndpoint($registry, new InMemorySessionStore());
    }

    private function turn(string $id, string $step, string $type, $value = null): array
    {
        return ['session' => $id, 'action' => 'turn', 'turn' => ['step' => $step, 'type' => $type, 'value' => $value]];
    }

    public function test_success_body_has_exactly_the_widget_contract_keys(): void
    {
        $r = $this->endpoint()->handle('lead', ['action' => 'start']);
        $keys = array_keys($r['body']);
        sort($keys);
        $expected = ['cursor', 'defaultMessages', 'error', 'messages', 'progress', 'session', 'status', 'step', 'summary', 'ui'];
        sort($expected);
        $this->assertSame($expected, $keys);
        $this->assertIsArray($r['body']['messages']);
        $this->assertIsInt($r['body']['cursor']);
        $this->assertTrue($r['body']['defaultMessages']);
    }

    public function test_error_body_shape(): void
    {
        $r = $this->endpoint()->handle('nope', []);
        $this->assertSame(404, $r['status']);
        $this->assertSame(['status' => 'error', 'error' => 'Unknown flow.'], $r['body']);
    }

    public function test_turn_on_unknown_but_well_formed_session_is_404(): void
    {
        $r = $this->endpoint()->handle('lead', $this->turn(str_repeat('a', 32), 'name', 'text', 'X'));
        $this->assertSame(404, $r['status']);
        $this->assertSame('Session not found.', $r['body']['error']);
    }

    public function test_turn_without_step_is_malformed(): void
    {
        $ep = $this->endpoint();
        $id = $ep->handle('lead', ['action' => 'start'])['body']['session'];
        $r = $ep->handle('lead', ['session' => $id, 'action' => 'turn', 'turn' => ['type' => 'text', 'value' => 'X']]);
        $this->assertSame(400, $r['status']);
        $this->assertSame('Malformed turn.', $r['body']['error']);
    }

    public function test_object_value_is_malformed(): void
    {
        $ep = $this->endpoint();
        $id = $ep->handle('lead', ['action' => 'start'])['body']['session'];
        $r = $ep->handle('lead', $this->turn($id, 'name', 'text', new \stdClass()));
        $this->assertSame(400, $r['status']);
        $this->assertSame('Malformed turn value.', $r['body']['error']);
    }

    public function test_invalid_locale_reaches_factory_as_null(): void
    {
        $seen = [];
        $registry = (new FlowRegistry())->register('lead', static function (?string $locale) use (&$seen): Flow {
            $seen = $locale;
            return Flow::make()->add(Steps::text('name', 'Name'));
        });
        $ep = new ChatEndpoint($registry, new InMemorySessionStore());

        $ep->handle('lead', ['action' => 'start', 'locale' => '<script>']);
        $this->assertNull($seen);

        $ep->handle('lead', ['action' => 'start', 'locale' => 'fa-IR']);
        $this->assertSame('fa-IR', $seen);
    }

    public function test_restart_issues_a_new_session_id(): void
    {
        $ep = $this->endpoint();
        $id1 = $ep->handle('lead', ['action' => 'start'])['body']['session'];
        $r = $ep->handle('lead', ['action' => 'restart', 'session' => $id1]);
        $id2 = $r['body']['session'];
        $this->assertNotSame($id1, $id2);
        $this->assertSame('name', $r['body']['step']);
    }

    public function test_state_after_completion_reports_completed(): void
    {
        $ep = $this->endpoint();
        $id = $ep->handle('lead', ['action' => 'start'])['body']['session'];
        $ep->handle('lead', $this->turn($id, 'name', 'text', 'Farid'));
        $ep->handle('lead', $this->turn($id, 'email', 'text', 'f@example.com'));
        $ep->handle('lead', $this->turn($id, 'done', 'submit', true));

        $r = $ep->handle('lead', ['action' => 'state', 'session' => $id]);
        $this->assertSame('completed', $r['body']['status']);
    }

    public function test_review_summary_rows_have_contract_fields(): void
    {
        $ep = $this->endpoint();
        $id = $ep->handle('lead', ['action' => 'start'])['body']['session'];
        $ep->handle('lead', $this->turn($id, 'name', 'text', 'Farid'));
        $ep->handle('lead', $this->turn($id, 'email', 'text', 'f@example.com'));

        $r = $ep->handle('lead', ['action' => 'state', 'session' => $id]);
        foreach ($r['body']['summary'] as $row) {
            $keys = array_keys($row);
            sort($keys);
            $this->assertSame(['editable', 'label', 'step', 'value'], $keys);
        }
    }

    public function test_edit_from_review_returns_to_step_then_back_to_review(): void
    {
        $ep = $this->endpoint();
        $id = $ep->handle('lead', ['action' => 'start'])['body']['session'];
        $ep->handle('lead', $this->turn($id, 'name', 'text', 'Farid'));
        $ep->handle('lead', $this->turn($id, 'email', 'text', 'f@example.com'));

        $r1 = $ep->handle('lead', $this->turn($id, 'done', 'edit', 'email'));
        $this->assertSame('email', $r1['body']['step']);

        $ep->handle('lead', $this->turn($id, 'email', 'text', 'new@example.com'));

        $r2 = $ep->handle('lead', ['action' => 'state', 'session' => $id]);
        $this->assertSame('review', $r2['body']['ui']['type']);
        foreach ($r2['body']['summary'] as $row) {
            if ($row['step'] === 'email') {
                $this->assertSame('new@example.com', $row['value']);
            }
        }
    }
}
