<?php

namespace App\Jobs;

use App\Common\Command\AdbCmd;
use App\Common\Command\PyCmd;
use App\Common\Constant\TypeTask;
use App\Common\Database\Redis\SamegoRedis;
use App\Common\System\CliLog;
use App\Service\PlaybookService;
use App\Utils\ObjectUtil;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;

class TaskQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 重试3次
    protected $tries = 1;

    private $playbook;
    private $type;
    private $playbookPath;
    private $device;

    private $result = false;

    public function __construct($type, $playbook, $device)
    {
        $this->type         = $type;
        $this->playbook     = $playbook;
        $this->playbookPath = PlaybookService::path($type, $playbook);
        $this->device       = $device;
    }


    public function handle(PlaybookService $playbookService)
    {
        switch ($this->type) {
            case TypeTask::SCRIPT:
                $this->result = $this->scriptHandle();
                break;
            case TypeTask::PLAYBOOK:
                $this->result = $this->playbookHandle();
                break;
            default:
                break;
        }
        if ($this->result) {
//            $playbookService->setDone($this->device, $this->playbook, $this->type);
        }
        return true;
    }

    private function scriptHandle()
    {
        $lastTime = 0;
        $file = fopen($this->playbookPath, "r") or die("Unable to open file!");
        // 输出单行直到 end-of-file
        while (!feof($file)) {
            $content = fgets($file);
            if (null == $content || 0 == count(explode(' ', $content))) continue;
            list($time, $dev, $type, $code, $data) = explode(' ', $content);
            $time      = intval($time);//微妙
            $delayTime = $time - $lastTime;
            if (0 < $lastTime && 0 < $delayTime) {
                usleep($delayTime);
            };
            $lastTime = $time;
            $command  = str_replace('{deviceNo}', $this->device, AdbCmd::ADB_SHELL) . " sendevent {$dev} {$type} {$code} {$data}";
            system($command, $status);
            Log::info($this->device . "\t" . $command . "\t" . $status . "\n");

            // 指令执行失败 队列失败重试
            if (0 != $status) {
                $this->fail();
                return false;
            }
        }
        $lastTime = null;
        fclose($file);
        return true;
    }

    private function playbookHandle()
    {
        $file = fopen($this->playbookPath, "r") or die("Unable to open file!");
        $content = fread($file, filesize($this->playbookPath));
        fclose($file);
        $executeStepList = ObjectUtil::jsonDecode($content);
        if (false == $executeStepList) {
            return false;
        }
        foreach ($executeStepList as $stepItem) {
            $command = str_replace('{deviceNo}', $this->device, AdbCmd::ADB_SHELL) . ' ' . $stepItem->command;
            exec($command, $result, $status);
            CliLog::info($this->device . "\t" . $command . "\t" . json_encode($result, JSON_UNESCAPED_UNICODE) . "\t" . $status . "\n");
            // 指令执行失败 队列失败重试
            if (0 != $status) {
                $this->fail();
                return false;
            }
            sleep($stepItem->time);
        }
        return true;
    }
}
