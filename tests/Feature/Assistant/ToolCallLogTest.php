<?php
namespace Tests\Feature\Assistant;

use App\Models\AssistantToolCall;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\Assistant\AssistantCallLog;
use App\Services\Assistant\AssistantToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proving a phantom "Done!" needs a record of what the assistant actually
 * called. These pin that record: every tool call through the registry lands a
 * row, tagged with the conversation so a turn's claims can be read beside the
 * tools it really invoked.
 */
class ToolCallLogTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code): Shop
    {
        $shop = Shop::create(['name' => 'S', 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $shop->update(['modules' => ['bookings']]);
        return $shop;
    }

    private function registry(): AssistantToolRegistry
    {
        return app(AssistantToolRegistry::class);
    }

    public function test_every_tool_call_lands_a_row_with_its_arguments(): void
    {
        $shop = $this->shop('7500');

        $this->registry()->execute($shop, 'create_staff', ['name' => 'Jhon']);

        $row = AssistantToolCall::where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame('create_staff', $row->tool);
        $this->assertSame(['name' => 'Jhon'], $row->input);
        $this->assertFalse($row->user_confirmed);
        $this->assertIsInt($row->duration_ms);
    }

    public function test_outcome_distinguishes_preview_applied_error_and_read(): void
    {
        $shop = $this->shop('7501');
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        $this->registry()->execute($shop, 'create_staff', ['name' => 'Jhon']);             // preview
        $this->registry()->execute($shop, 'create_staff', ['name' => 'Jhon', 'confirmed' => true]); // applied
        $this->registry()->execute($shop, 'update_staff', ['name' => 'Nobody', 'is_active' => false]); // error
        $this->registry()->execute($shop, 'list_staff', []);                                // read

        $this->assertSame(
            ['preview', 'applied', 'error', 'read'],
            AssistantToolCall::where('shop_id', $shop->id)->orderBy('id')->pluck('outcome')->all(),
        );
    }

    public function test_a_tool_the_model_invented_is_logged_as_an_error(): void
    {
        $shop = $this->shop('7502');

        $this->registry()->execute($shop, 'summon_a_dragon', ['size' => 'large']);

        $row = AssistantToolCall::where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame('summon_a_dragon', $row->tool);
        $this->assertSame('error', $row->outcome);
        $this->assertSame(['error' => 'unknown_tool'], $row->result);
    }

    public function test_a_user_confirmed_call_is_distinguishable_from_a_model_driven_one(): void
    {
        $shop = $this->shop('7503');
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        $this->registry()->execute($shop, 'delete_staff', ['name' => 'Ali', 'confirmed' => true]); // model
        $this->registry()->execute($shop, 'delete_staff', ['name' => 'Ali'], userConfirmed: true); // owner tap

        $rows = AssistantToolCall::where('shop_id', $shop->id)->orderBy('id')->get();
        $this->assertFalse($rows[0]->user_confirmed);
        $this->assertSame('preview', $rows[0]->outcome);  // destructive: the model cannot write
        $this->assertTrue($rows[1]->user_confirmed);
        $this->assertSame('applied', $rows[1]->outcome);
    }

    public function test_long_input_and_result_are_truncated(): void
    {
        $shop = $this->shop('7504');

        $this->registry()->execute($shop, 'create_staff', ['name' => str_repeat('x', 5000)]);

        $row = AssistantToolCall::where('shop_id', $shop->id)->firstOrFail();
        $this->assertLessThanOrEqual(2000, strlen(json_encode($row->input)));
        $this->assertLessThanOrEqual(2000, strlen(json_encode($row->result)));
    }

    public function test_a_broken_logger_never_breaks_the_tool_call(): void
    {
        $shop = $this->shop('7505');

        // Any failure in the log path must be swallowed: a broken log is an
        // inconvenience, a broken assistant is an outage.
        $this->app->instance(AssistantCallLog::class, new class extends AssistantCallLog {
            public function record(int $shopId, string $tool, array $input, array $result, bool $userConfirmed, int $durationMs): void
            {
                throw new \RuntimeException('log is down');
            }
        });

        $out = json_decode($this->registry()->execute($shop, 'create_staff', ['name' => 'Jhon', 'confirmed' => true]), true);

        $this->assertTrue($out['done']);
        $this->assertSame(1, Staff::where('shop_id', $shop->id)->count()); // the write still happened
    }

    public function test_rows_are_tagged_with_the_conversation_and_backfilled_when_it_appears_later(): void
    {
        $shop = $this->shop('7506');
        $log = app(AssistantCallLog::class);

        // A brand-new thread: the tool runs before any conversation exists.
        $log->forConversation(null);
        $this->registry()->execute($shop, 'create_staff', ['name' => 'Jhon']);
        $this->registry()->execute($shop, 'list_staff', []);
        $this->assertSame([null, null], AssistantToolCall::orderBy('id')->pluck('conversation_id')->all());

        // The controller creates the thread after a successful reply, then backfills.
        $log->backfillConversation(42);

        $this->assertSame([42, 42], AssistantToolCall::orderBy('id')->pluck('conversation_id')->all());
    }

    public function test_the_backfill_leaves_rows_that_already_have_a_conversation_alone(): void
    {
        $shop = $this->shop('7507');
        $log = app(AssistantCallLog::class);

        $log->forConversation(7);
        $this->registry()->execute($shop, 'list_staff', []);

        $log->backfillConversation(42);

        $this->assertSame(7, AssistantToolCall::firstOrFail()->conversation_id);
    }

    public function test_prune_drops_rows_older_than_the_retention_window(): void
    {
        $shop = $this->shop('7508');
        DB::table('assistant_tool_calls')->insert([
            ['shop_id' => $shop->id, 'tool' => 'old', 'input' => '[]', 'result' => '[]', 'outcome' => 'read',
             'user_confirmed' => false, 'duration_ms' => 1, 'created_at' => now()->subDays(31)],
            ['shop_id' => $shop->id, 'tool' => 'recent', 'input' => '[]', 'result' => '[]', 'outcome' => 'read',
             'user_confirmed' => false, 'duration_ms' => 1, 'created_at' => now()->subDays(29)],
        ]);

        $this->artisan('assistant:prune-tool-calls --days=30')->assertSuccessful();

        $this->assertSame(['recent'], AssistantToolCall::pluck('tool')->all());
    }
}
