<?php

class TestFile
{
    // FIXME: code using this directly needs to be refactored
    public array $sections = ['TEST' => ''];
    private ?string $borkage = null;
    private string $file;

    private const ALLOWED_SECTIONS = [
        'EXPECT', 'EXPECTF', 'EXPECTREGEX', 'EXPECTREGEX_EXTERNAL', 'EXPECT_EXTERNAL', 'EXPECTF_EXTERNAL', 'EXPECTHEADERS',
        'POST', 'POST_RAW', 'GZIP_POST', 'DEFLATE_POST', 'PUT', 'GET', 'COOKIE', 'ARGS',
        'FILE', 'FILEEOF', 'FILE_EXTERNAL', 'REDIRECTTEST',
        'CAPTURE_STDIO', 'STDIN', 'CGI', 'PHPDBG',
        'INI', 'ENV', 'EXTENSIONS',
        'SKIPIF', 'XFAIL', 'XLEAK', 'CLEAN',
        'CREDITS', 'DESCRIPTION', 'CONFLICTS', 'WHITESPACE_SENSITIVE',
    ];

    public function __construct(string $file, bool $inRedirect)
    {
        $this->file = $file;

        // Load the sections of the test file.
        $fp = fopen($file, "rb") or error("Cannot open test file: $file");

        if (!feof($fp)) {
            $line = fgets($fp);

            if ($line === false) {
                $this->borkage = "cannot read test";
            }
        } else {
            $this->borkage = "empty test [$file]";
        }
        if ($this->borkage === null && strncmp('--TEST--', $line, 8)) {
            $this->borkage = "tests must start with --TEST-- [$file]";
        }

        $section = 'TEST';
        $secfile = false;
        $secdone = false;

        while (!feof($fp)) {
            $line = fgets($fp);

            if ($line === false) {
                break;
            }

            // Match the beginning of a section.
            if (preg_match('/^--([_A-Z]+)--/', $line, $r)) {
                $section = (string) $r[1];

                if ($this->hasSection($section) && $this->sections[$section]) {
                    $this->borkage = "duplicated $section section";
                }

                // check for unknown sections
                if (!in_array($section, self::ALLOWED_SECTIONS)) {
                    $this->borkage = 'Unknown section "' . $section . '"';
                }

                $this->sections[$section] = '';
                $secfile = $section == 'FILE' || $section == 'FILEEOF' || $section == 'FILE_EXTERNAL';
                $secdone = false;
                continue;
            }

            // Add to the section text.
            if (!$secdone) {
                $this->sections[$section] .= $line;
            }

            // End of actual test?
            if ($secfile && preg_match('/^===DONE===\s*$/', $line)) {
                $secdone = true;
            }
        }
        fclose($fp);

        // the redirect section allows a set of tests to be reused outside of
        // a given test dir
        if ($this->borkage === null) {
            $this->borkage = $this->process($inRedirect);
        }
    }

    public function getBorkage(): ?string
    {
        return $this->borkage;
    }

    public function getSections(): array
    {
        return $this->sections;
    }

    public function getSection(string $name, ?string $default = null): ?string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return array_key_exists($name, $this->sections);
    }

    public function getTestName(): string
    {
        return trim($this->getSection('TEST'));
    }

    public function isCGITest(): bool
    {
        return $this->hasSection('CGI')
            || !empty($this->sections['GET']) 
            || !empty($this->sections['POST']) 
            || !empty($this->sections['GZIP_POST']) 
            || !empty($this->sections['DEFLATE_POST']) 
            || !empty($this->sections['POST_RAW']) 
            || !empty($this->sections['PUT']) 
            || !empty($this->sections['COOKIE']) 
            || !empty($this->sections['EXPECTHEADERS']);
    }

    private function process(bool $inRedirect): ?string
    {
        // the redirect section allows a set of tests to be reused outside of
        // a given test dir
        if ($this->hasSection('REDIRECTTEST')) {
            if ($inRedirect) {
                return "Can't redirect a test from within a redirected test";
            }
            return null;
        }

        if (!$this->hasSection('PHPDBG') && $this->hasSection('FILE') + $this->hasSection('FILEEOF') + $this->hasSection('FILE_EXTERNAL') != 1) {
            return "missing section --FILE--";
        }

        if ($this->hasSection('FILEEOF')) {
            $this->sections['FILE'] = preg_replace("/[\r\n]+$/", '', $this->sections['FILEEOF']);
            unset($this->sections['FILEEOF']);
        }

        foreach (['FILE', 'EXPECT', 'EXPECTF', 'EXPECTREGEX'] as $prefix) {
            $key = $prefix . '_EXTERNAL';

            if ($this->hasSection($key)) {
                // don't allow tests to retrieve files from anywhere but this subdirectory
                $this->sections[$key] = dirname($this->file) . '/' . trim(str_replace('..', '', $this->sections[$key]));

                if (file_exists($this->sections[$key])) {
                    $this->sections[$prefix] = file_get_contents($this->sections[$key]);
                    unset($this->sections[$key]);
                } else {
                    return "could not load --" . $key . "-- " . dirname($this->file) . '/' . trim($this->sections[$key]);
                }
            }
        }

        if (($this->hasSection('EXPECT') + $this->hasSection('EXPECTF') + $this->hasSection('EXPECTREGEX')) != 1) {
            return "missing section --EXPECT--, --EXPECTF-- or --EXPECTREGEX--";
        }

        if ($this->hasSection('PHPDBG') && !$this->hasSection('STDIN')) {
            $this->sections['STDIN'] = $this->sections['PHPDBG'] . "\n";
        }

        return null;
    }
}
