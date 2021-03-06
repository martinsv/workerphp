<?php
namespace Kohkimakimoto\Worker\Job;

use Kohkimakimoto\Worker\Worker;
use Symfony\Component\Process\Process;

class JobManager
{
    protected $jobs = [];

    protected $config;

    protected $output;

    protected $eventLoop;

    public function __construct($config, $output, $eventLoop)
    {
        $this->config = $config;
        $this->output = $output;
        $this->eventLoop = $eventLoop;
    }

    public function register($name, $command)
    {
        // checks if the same name exists.
        if (array_key_exists($name, $this->jobs)) {
            throw new \InvalidArgumentException("'$name' is already registered as a job.");
        }

        $id = count($this->jobs);
        $this->jobs[$name] = new Job($id, $name, $command, $this->config);

        return $this;
    }

    public function boot()
    {
        // All registered jobs is initialized.
        $bootTime = new \DateTime();
        foreach ($this->jobs as $job) {
            $this->output->writeln("<info>Initializing job:</info> <comment>".$job->getName()."</comment> (job_id: <comment>".$job->getId()."</comment>)");
            $job->setLastRunTime($bootTime);

            if ($job->hasCronTime()) {
                $this->addJobAsTimer($job);
            }
        }
    }

    protected function addJobAsTimer($job)
    {
        $job->updateNextRunTime();
        $worker = $this;
        $secondsOfTimer = $job->secondsUntilNextRuntime();

        $this->eventLoop->addTimer($secondsOfTimer, function () use ($job, $worker) {
            $id = $job->getId();
            $name = $job->getName();
            $output = $worker->output;

            $now = new \DateTime();

            if ($output->isDebug()) {
                $output->writeln("[debug] Try running a job: $name at ".$now->format('Y-m-d H:i:s'));
            }

            $runtimeJob = $job->makeRuntimeJob();

            $pid = pcntl_fork();
            if ($pid === -1) {
                // Error
                throw new \RuntimeException("pcntl_fork error.");
            } elseif ($pid) {
                // Parent process

                // wait
                $status = null;
                $pid = pcntl_wait($status);
                if (!$pid) {
                    throw new \RuntimeException("pcntl_wait error.");
                }

                // add next timer
                $job->setLastRunTime($now);
                $worker->addJobAsTimer($job);
            } else {
                // Child process

                // Remove tty to ignore signals from tty.
                posix_setsid();

                // Forks it one more time to prevent to be zombie process.
                $pid = pcntl_fork();
                if ($pid === -1) {
                    // Error
                    throw new \RuntimeException("pcntl_fork error.");
                } elseif ($pid) {
                    exit;
                }

                if ($output->isDebug()) {
                    $output->writeln("[debug] Forked process for: $name (pid:".posix_getpid().")");
                }

                if ($job->isLimitOfProcesses()) {
                    $output->writeln("<fg=magenta>Skip the job '$name' due to limit of max processes: ".$job->getMaxProcesses()." at ".$now->format('Y-m-d H:i:s')."</fg=magenta>");
                    exit;
                }

                $runtimeJob->createRunFileWithPid(posix_getpid());
                if ($output->isDebug()) {
                    $output->writeln("[debug] Create run file '".$runtimeJob->getRunFile()."' for running job: $name");
                }

                $command = $job->getCommand();
                $output->writeln("<info>Running job:</info> <comment>$name</comment> (pid: ".posix_getpid().") at ".$now->format('Y-m-d H:i:s'));

                if ($command instanceof \Closure) {
                    // command is a closure
                    call_user_func($command, $worker);
                } elseif (is_string($command)) {
                    // command is a string
                    $process = new Process($command);
                    $process->setTimeout(null);

                    $process->run(function ($type, $buffer) use ($output) {
                        $output->write($buffer);
                    });
                } else {
                    throw new \RuntimeException("Unsupported operation.");
                }

                $file = $runtimeJob->getRunFile();
                $runtimeJob->removeRunFile();
                if ($output->isDebug()) {
                    $output->writeln("[debug] Removed run file '".$file."'. Finished the job: $name");
                }

                exit;
            }
        });

        if ($this->output->isDebug()) {
            $this->output->writeln("[debug] Added new timer: '".$job->getNextRunTime()->format('Y-m-d H:i:s')."' (after ".$secondsOfTimer." seconds) (job: ".$job->getName().").");
        }
    }

    public function getJobs()
    {
        return $this->jobs;
    }
}
