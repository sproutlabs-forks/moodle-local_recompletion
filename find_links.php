<?php
// Usage: php find_links_cli.php
define('CLI_SCRIPT', true);

require_once(dirname(__DIR__, 2) . '/config.php');
global $DB;

$search = 'dhhs.sproutlabs.com.au';
$matches = [];

echo "🔍 Searching database for '$search'...\n";

$tables = $DB->get_tables();

foreach ($tables as $table) {
    try {
        $columns = $DB->get_columns($table);
        $conditions = [];
        $params = [];

        foreach ($columns as $column => $meta) {
            if (in_array($meta->meta_type, ['C', 'X'])) {
                $conditions[] = "$column LIKE ?";
                $params[] = "%$search%";
            }
        }

        if (!empty($conditions)) {
            $sql = "SELECT * FROM {{$table}} WHERE " . implode(" OR ", $conditions);
            $results = $DB->get_records_sql($sql, $params);

            if (!empty($results)) {
                $matches[$table] = count($results);
                echo "✅ Found in table: $table (" . count($results) . " rows)\n";

                // Print matches with course ID if available
                foreach ($results as $rec) {
                    if (isset($rec->course)) {
                        echo "   ➤ course id: {$rec->course}\n";
                    } elseif (isset($rec->courseid)) {
                        echo "   ➤ course id: {$rec->courseid}\n";
                    } elseif (isset($rec->id)) {
                        echo "   ➤ row id: {$rec->id}\n";
                    } else {
                        echo "   ➤ course id: (not available)\n";
                    }
                }

            }
        }
    } catch (Exception $e) {
        echo "⚠️ Skipped table '$table': " . $e->getMessage() . "\n";
    }
}

echo "\n=== SUMMARY ===\n";
if (!empty($matches)) {
    foreach ($matches as $table => $count) {
        echo "📌 $table: $count rows matched\n";
    }
} else {
    echo "❌ No matches found for '$search'.\n";
}

exit(0);
