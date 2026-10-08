<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Unit;

use Elgiosoft\Logger\Support\BindingNames;
use PHPUnit\Framework\TestCase;

final class BindingNamesTest extends TestCase
{
    public function test_where_in_between_and_update_placeholders(): void
    {
        $this->assertSame(
            ['user_id', 'status', 'status', 'amount', 'amount', 'created_at'],
            BindingNames::infer('select * from `wallets` where `wallets`.`user_id` = ? and "status" in (?, ?) and amount between ? and ? and created_at >= ?', 6),
        );

        $this->assertSame(['pin', 'balance', 'id'], BindingNames::infer('update `wallets` set `pin` = ?, `balance` = ? where `id` = ?', 3));
    }

    public function test_insert_columns_map_by_position_across_rows(): void
    {
        $this->assertSame(
            ['user_id', 'pin', 'user_id', 'pin'],
            BindingNames::infer('insert into `wallets` (`user_id`, `pin`) values (?, ?), (?, ?)', 4),
        );
    }

    public function test_unknown_positions_and_question_marks_in_literals(): void
    {
        $this->assertSame([null, 'id'], BindingNames::infer("select coalesce(?, 'why?') from t where id = ?", 2));
        $this->assertSame([], BindingNames::infer('select 1', 0));
    }
}
