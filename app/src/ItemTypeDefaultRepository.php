<?php
declare(strict_types=1);

namespace App;

use PDO;

final class ItemTypeDefaultRepository
{
    /**
     * @var array<string, string>
     */
    private const ITEM_TYPE_TO_BOX = [
        'M' => 'MEAL',
        'P' => 'PROTEIN',
        'S' => 'SAUCE',
        'B' => 'SIDE',
        'Z' => 'BASE',
        'F' => 'BREAKFAST',
        'D' => 'DESSERT',
        'X' => 'MISC',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDefaults(): array
    {
        $stmt = $this->pdo->query('SELECT item_type, note, best_before_days, thaw_method, reheat_minutes FROM item_type_defaults ORDER BY item_type ASC');
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $boxType = self::ITEM_TYPE_TO_BOX[$row['item_type']] ?? null;
            if ($boxType === null) {
                continue;
            }

            $result[] = [
                'item_type' => $row['item_type'],
                'box_type' => $boxType,
                'note' => $row['note'],
                'best_before_days' => (int)$row['best_before_days'],
                'thaw_method' => $row['thaw_method'],
                'reheat_minutes' => $row['reheat_minutes'] !== null ? (int)$row['reheat_minutes'] : null,
            ];
        }

        return $result;
    }
}
