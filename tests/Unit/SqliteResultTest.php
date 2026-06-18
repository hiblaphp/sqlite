<?php

declare(strict_types=1);

use Hibla\Sqlite\Interfaces\SqliteResult;
use Hibla\Sqlite\Internals\Result;

describe('SqliteResult Value Object', function (): void {

    it('initializes with correct defaults and metadata from rows', function (): void {
        $rows = [
            ['id' => 1, 'name' => 'Alice', 'role' => 'Admin'],
            ['id' => 2, 'name' => 'Bob', 'role' => 'User'],
        ];

        $result = new Result(
            affectedRows: 2,
            lastInsertId: 42,
            connectionId: 101,
            rows: $rows
        );

        expect($result)->toBeInstanceOf(SqliteResult::class);

        expect($result->rowCount)->toBe(2)
            ->and($result->columnCount)->toBe(3)
            ->and($result->columns)->toBe(['id', 'name', 'role'])
            ->and($result->connectionId)->toBe(101)
            ->and($result->affectedRows)->toBe(2)
            ->and($result->lastInsertId)->toBe(42)
            ->and($result->hasAffectedRows())->toBeTrue()
            ->and($result->hasLastInsertId())->toBeTrue()
            ->and($result->isEmpty())->toBeFalse()
        ;
    });

    it('handles empty rows correctly', function (): void {
        $result = new Result(0, 0, 101, []);

        expect($result->rowCount)->toBe(0)
            ->and($result->columnCount)->toBe(0)
            ->and($result->columns)->toBe([])
            ->and($result->hasAffectedRows())->toBeFalse()
            ->and($result->hasLastInsertId())->toBeFalse()
            ->and($result->isEmpty())->toBeTrue()
        ;
    });

    it('supports sequential row fetching using fetchAssoc()', function (): void {
        $rows = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $result = new Result(0, 0, 1, $rows);

        expect($result->fetchAssoc()['name'])->toBe('Alice')
            ->and($result->fetchAssoc()['name'])->toBe('Bob')
            ->and($result->fetchAssoc())->toBeNull()
        ;
    });

    it('supports fetching a single column array using fetchColumn() with different types', function (): void {
        $rows = [
            ['id' => 10, 'name' => 'Alice'],
            ['id' => 20, 'name' => 'Bob'],
        ];

        $result = new Result(0, 0, 1, $rows);

        expect($result->fetchColumn('name'))->toBe(['Alice', 'Bob']);
        expect($result->fetchColumn(0))->toBe([10, 20]);
    });

    it('supports standard iteration via foreach using getIterator()', function (): void {
        $rows = [
            ['id' => 1],
            ['id' => 2],
        ];

        $result = new Result(0, 0, 1, $rows);

        $collected = [];
        foreach ($result as $row) {
            $collected[] = $row['id'];
        }

        expect($collected)->toBe([1, 2]);
    });
});
