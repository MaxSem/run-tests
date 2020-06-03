<?php

class RuntestsValgrind
{
    protected $version = '';
    protected $header = '';
    protected $version_3_3_0 = false;
    protected $version_3_8_0 = false;
    protected $tool = null;

    public function getVersion()
    {
        return $this->version;
    }

    public function getHeader()
    {
        return $this->header;
    }

    public function __construct(array $environment, string $tool = 'memcheck')
    {
        $this->tool = $tool;
        $header = system_with_timeout("valgrind --tool={$this->tool} --version", $environment);
        if (!$header) {
            error("Valgrind returned no version info for {$this->tool}, cannot proceed.\n".
                  "Please check if Valgrind is installed and the tool is named correctly.");
        }
        $count = 0;
        $version = preg_replace("/valgrind-(\d+)\.(\d+)\.(\d+)([.\w_-]+)?(\s+)/", '$1.$2.$3', $header, 1, $count);
        if ($count != 1) {
            error("Valgrind returned invalid version info (\"{$header}\") for {$this->tool}, cannot proceed.");
        }
        $this->version = $version;
        $this->header = sprintf(
            "%s (%s)", trim($header), $this->tool);
        $this->version_3_3_0 = version_compare($version, '3.3.0', '>=');
        $this->version_3_8_0 = version_compare($version, '3.8.0', '>=');
    }

    public function wrapCommand($cmd, $memcheck_filename, $check_all)
    {
        $vcmd = "valgrind -q --tool={$this->tool} --trace-children=yes";
        if ($check_all) {
            $vcmd .= ' --smc-check=all';
        }

        /* --vex-iropt-register-updates=allregs-at-mem-access is necessary for phpdbg watchpoint tests */
        if ($this->version_3_8_0) {
            /* valgrind 3.3.0+ doesn't have --log-file-exactly option */
            return "$vcmd --vex-iropt-register-updates=allregs-at-mem-access --log-file=$memcheck_filename $cmd";
        } elseif ($this->version_3_3_0) {
            return "$vcmd --vex-iropt-precise-memory-exns=yes --log-file=$memcheck_filename $cmd";
        } else {
            return "$vcmd --vex-iropt-precise-memory-exns=yes --log-file-exactly=$memcheck_filename $cmd";
        }
    }
}
