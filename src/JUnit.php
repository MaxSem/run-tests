<?php

class JUnit
{
    private bool $enabled = true;
    private $fp = null;
    private array $suites = [];
    private array $rootSuite = self::EMPTY_SUITE + ['name' => 'php'];

    private const EMPTY_SUITE = [
        'test_total' => 0,
        'test_pass' => 0,
        'test_fail' => 0,
        'test_error' => 0,
        'test_skip' => 0,
        'test_warn' => 0,
        'files' => [],
        'execution_time' => 0,
    ];

    public function __construct(array $env, int $workerID)
    {
        // Check whether a junit log is wanted.
        $fileName = $env['TEST_PHP_JUNIT'] ?? null;
        if (empty($fileName)) {
            $this->enabled = false;
            return;
        }
        if (!$workerID && !$this->fp = fopen($fileName, 'w')) {
            throw new Exception("Failed to open $fileName for writing.");
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function saveXML(): void
    {
        if (!$this->enabled) {
            return;
        }

        $xml = '<' . '?' . 'xml version="1.0" encoding="UTF-8"' . '?' . '>' . PHP_EOL;
        $xml .= sprintf(
            '<testsuites name="%s" tests="%s" failures="%d" errors="%d" skip="%d" time="%s">' . PHP_EOL,
            $this->rootSuite['name'],
            $this->rootSuite['total'],
            $this->rootSuite['failed'],
            $this->rootSuite['errors'],
            $this->rootSuite['skipped'],
            $this->rootSuite['executionTime']
        );
        $xml .= $this->getSuitesXML();
        $xml .= '</testsuites>';
        fwrite($this->fp, $xml);
    }

    private function getSuitesXML($suite_name = '')
    {
        // FIXME: $suite_name gets overwritten
        $result = '';

        foreach ($this->suites as $suite_name => $suite) {
            $result .= sprintf(
                '<testsuite name="%s" tests="%s" failures="%d" errors="%d" skip="%d" time="%s">' . PHP_EOL,
                $suite['name'],
                $suite['test_total'],
                $suite['test_fail'],
                $suite['test_error'],
                $suite['test_skip'],
                $suite['execution_time']
            );

            if (!empty($suite_name)) {
                foreach ($suite['files'] as $file) {
                    $result .= $this->rootSuite['files'][$file]['xml'];
                }
            }

            $result .= '</testsuite>' . PHP_EOL;
        }

        return $result;
    }

    /**
     * @param array|string $type
     * @param string $file_name
     * @param string $test_name
     * @param int|string $time
     * @param string $message
     * @param string $details
     *
     * @return void
     */
    public function markTestAs($type, $file_name, $test_name, $time = null, $message = '', $details = '')
    {
        if (!$this->enabled) {
            return;
        }

        $suite = $this->getSuiteName($file_name);

        $this->record($suite, 'test_total');

        $time = $time ?? $this->getTimer($file_name);
        $this->record($suite, 'execution_time', $time);

        $escaped_details = htmlspecialchars($details, ENT_QUOTES, 'UTF-8');
        $escaped_details = preg_replace_callback('/[\0-\x08\x0B\x0C\x0E-\x1F]/', function ($c) {
            return sprintf('[[0x%02x]]', ord($c[0]));
        }, $escaped_details);
        $escaped_message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $escaped_test_name = htmlspecialchars($file_name . ' (' . $test_name . ')', ENT_QUOTES);
        $this->rootSuite['files'][$file_name]['xml'] = "<testcase name='$escaped_test_name' time='$time'>\n";

        if (is_array($type)) {
            $output_type = $type[0] . 'ED';
            $temp = array_intersect(array('XFAIL', 'XLEAK', 'FAIL', 'WARN'), $type);
            $type = reset($temp);
        } else {
            $output_type = $type . 'ED';
        }

        if ('PASS' == $type || 'XFAIL' == $type || 'XLEAK' == $type) {
            $this->record($suite, 'test_pass');
        } elseif ('BORK' == $type) {
            $this->record($suite, 'test_error');
            $this->rootSuite['files'][$file_name]['xml'] .= "<error type='$output_type' message='$escaped_message'/>\n";
        } elseif ('SKIP' == $type) {
            $this->record($suite, 'test_skip');
            $this->rootSuite['files'][$file_name]['xml'] .= "<skipped>$escaped_message</skipped>\n";
        } elseif ('WARN' == $type) {
            $this->record($suite, 'test_warn');
            $this->rootSuite['files'][$file_name]['xml'] .= "<warning>$escaped_message</warning>\n";
        } elseif ('FAIL' == $type) {
            $this->record($suite, 'test_fail');
            $this->rootSuite['files'][$file_name]['xml'] .= "<failure type='$output_type' message='$escaped_message'>$escaped_details</failure>\n";
        } else {
            $this->record($suite, 'test_error');
            $this->rootSuite['files'][$file_name]['xml'] .= "<error type='$output_type' message='$escaped_message'>$escaped_details</error>\n";
        }

        $this->rootSuite['files'][$file_name]['xml'] .= "</testcase>\n";
    }

    private function record(string $suite, string $param, $value = 1): void
    {
        $this->rootSuite[$param] += $value;
        $this->suites[$suite][$param] += $value;
    }

    private function getTimer($file_name)
    {
        if (!$this->enabled) {
            return 0;
        }

        if (isset($this->rootSuite['files'][$file_name]['total'])) {
            return number_format($this->rootSuite['files'][$file_name]['total'], 4);
        }

        return 0;
    }

    public function startTimer($file_name): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!isset($this->rootSuite['files'][$file_name]['start'])) {
            $this->rootSuite['files'][$file_name]['start'] = microtime(true);

            $suite = $this->getSuiteName($file_name);
            $this->initSuite($suite);
            $this->suites[$suite]['files'][$file_name] = $file_name;
        }
    }

    public function getSuiteName(string $file_name): string
    {
        return $this->pathToClassName(dirname($file_name));
    }

    private function pathToClassName(string $file_name): string
    {
        if (!$this->enabled) {
            return '';
        }

        $ret = $this->rootSuite['name'];
        $_tmp = [];

        // lookup whether we're in the PHP source checkout
        $max = 5;
        if (is_file($file_name)) {
            $dir = dirname(realpath($file_name));
        } else {
            $dir = realpath($file_name);
        }
        do {
            array_unshift($_tmp, basename($dir));
            $chk = $dir . DIRECTORY_SEPARATOR . "main" . DIRECTORY_SEPARATOR . "php_version.h";
            $dir = dirname($dir);
        } while (!file_exists($chk) && --$max > 0);
        if (file_exists($chk)) {
            if ($max) {
                array_shift($_tmp);
            }
            foreach ($_tmp as $p) {
                $ret .= "." . preg_replace(",[^a-z0-9]+,i", ".", $p);
            }
            return $ret;
        }

        return $this->rootSuite['name'] . '.' . str_replace(array(DIRECTORY_SEPARATOR, '-'), '.', $file_name);
    }

    public function initSuite(string $suite_name): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!empty($this->suites[$suite_name])) {
            return;
        }

        $this->suites[$suite_name] = self::EMPTY_SUITE + ['name' => $suite_name];
    }

    public function stopTimer(string $file_name): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!isset($this->rootSuite['files'][$file_name]['start'])) {
            throw new Exception("Timer for $file_name was not started!");
        }

        if (!isset($this->rootSuite['files'][$file_name]['total'])) {
            $this->rootSuite['files'][$file_name]['total'] = 0;
        }

        $start = $this->rootSuite['files'][$file_name]['start'];
        $this->rootSuite['files'][$file_name]['total'] += microtime(true) - $start;
        unset($this->rootSuite['files'][$file_name]['start']);
    }

    public function mergeResults(JUnit $other): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->mergeSuites($this->rootSuite, $other->rootSuite);
        foreach ($other->suites as $name => $suite) {
            if (!isset($this->suites[$name])) {
                $this->suites[$name] = $suite;
                continue;
            }

            $this->mergeSuites($this->suites[$name], $suite);
        }
    }

    private function mergeSuites(array &$dest, array $source): void
    {
        $dest['test_total'] += $source['test_total'];
        $dest['test_pass']  += $source['test_pass'];
        $dest['test_fail']  += $source['test_fail'];
        $dest['test_error'] += $source['test_error'];
        $dest['test_skip']  += $source['test_skip'];
        $dest['test_warn']  += $source['test_warn'];
        $dest['execution_time'] += $source['execution_time'];
        $dest['files'] += $source['files'];
    }
}
