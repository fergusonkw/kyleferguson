<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class LogViewerController extends Controller
{
    private const MAX_LOG_READ_BYTES = 10 * 1024 * 1024; // 10 MB

    /**
     * Display the log viewer with a list of log files and parsed entries.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny-log-viewer');

        $logPath = storage_path('logs');
        $logFiles = $this->getLogFiles($logPath);
        $selectedFile = $request->query('file');
        $entries = [];
        $truncated = false;

        if ($selectedFile && $this->isValidLogFile($logPath, $selectedFile)) {
            $result = $this->parseLogFile($logPath.'/'.$selectedFile);
            $entries = $result['entries'];
            $truncated = $result['truncated'];
        } elseif ($logFiles->isNotEmpty()) {
            $selectedFile = $logFiles->first()['filename'];
            $result = $this->parseLogFile($logPath.'/'.$selectedFile);
            $entries = $result['entries'];
            $truncated = $result['truncated'];
        }

        return view('admin-v2.log-viewer.index', compact('logFiles', 'selectedFile', 'entries', 'truncated'));
    }

    /**
     * Return a single log entry's full details as JSON for the offcanvas.
     */
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewAny-log-viewer');

        $file = $request->query('file');
        $index = (int) $request->query('index', '0');
        $logPath = storage_path('logs');

        if (! $file || ! $this->isValidLogFile($logPath, $file)) {
            return response()->json(['error' => 'Invalid log file'], 404);
        }

        $result = $this->parseLogFile($logPath.'/'.$file);

        if (! isset($result['entries'][$index])) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        return response()->json($result['entries'][$index]);
    }

    /**
     * Get all .log files sorted by modification time descending.
     *
     * @return \Illuminate\Support\Collection<int, array{filename: string, size: string, modified: string, modified_timestamp: int}>
     */
    private function getLogFiles(string $logPath): \Illuminate\Support\Collection
    {
        if (! is_dir($logPath)) {
            return collect();
        }

        $files = glob($logPath.'/*.log');

        if ($files === false) {
            return collect();
        }

        return collect($files)
            ->map(function (string $filePath) {
                $size = filesize($filePath);

                return [
                    'filename' => basename($filePath),
                    'size' => $this->formatFileSize($size ?: 0),
                    'modified' => date('Y-m-d H:i:s', filemtime($filePath) ?: 0),
                    'modified_timestamp' => filemtime($filePath) ?: 0,
                ];
            })
            ->sortByDesc('modified_timestamp')
            ->values();
    }

    /**
     * Validate that the given filename is a real .log file inside the logs directory.
     */
    private function isValidLogFile(string $logPath, string $filename): bool
    {
        // Prevent directory traversal
        if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            return false;
        }

        $fullPath = $logPath.'/'.$filename;

        return str_ends_with($filename, '.log') && file_exists($fullPath) && is_file($fullPath);
    }

    /**
     * Parse a Laravel log file into structured entries.
     * For files larger than MAX_LOG_READ_BYTES, only the final chunk is read.
     *
     * @return array{entries: array<int, array{timestamp: string, level: string, environment: string, message: string, stack_trace: string}>, truncated: bool}
     */
    private function parseLogFile(string $filePath): array
    {
        if (! file_exists($filePath)) {
            return ['entries' => [], 'truncated' => false];
        }

        $fileSize = filesize($filePath);

        if ($fileSize === false || $fileSize === 0) {
            return ['entries' => [], 'truncated' => false];
        }

        $truncated = false;
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return ['entries' => [], 'truncated' => false];
        }

        if ($fileSize > self::MAX_LOG_READ_BYTES) {
            fseek($handle, -self::MAX_LOG_READ_BYTES, SEEK_END);
            fgets($handle); // discard partial line so we start at a clean boundary
            $truncated = true;
        }

        $content = stream_get_contents($handle);
        fclose($handle);

        if ($content === false) {
            return ['entries' => [], 'truncated' => false];
        }

        $pattern = '/\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\.?\d*[+\-\d:]*)\]\s+(\w+)\.(\w+):\s+(.*?)(?=\n\[\d{4}-\d{2}-\d{2}|\z)/s';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $entries = [];
        foreach ($matches as $match) {
            $fullMessage = mb_trim($match[4]);
            $messageParts = explode("\n", $fullMessage, 2);
            $message = $messageParts[0];
            $stackTrace = isset($messageParts[1]) ? mb_trim($messageParts[1]) : '';

            $entries[] = [
                'timestamp' => $match[1],
                'environment' => $match[2],
                'level' => mb_strtoupper($match[3]),
                'message' => $message,
                'stack_trace' => $stackTrace,
            ];
        }

        // Sort by timestamp descending (most recent first)
        usort($entries, fn (array $a, array $b) => strcmp($b['timestamp'], $a['timestamp']));

        return ['entries' => $entries, 'truncated' => $truncated];
    }

    /**
     * Format bytes into a human-readable file size.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }
}
