<?php

namespace App\Console\Commands\OneDrive;

use App\Helpers\Tool;
use App\Helpers\OneDrive;
use Illuminate\Console\Command;

class UploadFile extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'od:upload
                            {local : 本地文件地址}
                            {remote : 远程文件地址}
                            {--chuck=5242880 : 分块大小(字节)（320kib的倍数） }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'UploadFile File';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function handle()
    {
        $this->call('od:refresh');
        clearstatcache();
        $local = $this->argument('local');
        if (!is_file($local)) {
            $this->warn('暂不支持文件夹上传!');
            exit;
        }
        $remote = $this->argument('remote');
        $chuck = $this->option('chuck');
        $file_size = OneDrive::readFileSize($local);
        $this->info('开始上传...');
        if ($file_size < 4194304) {
            $this->upload($local, $remote);
        } else {
            $this->uploadBySession($local, $remote, $chuck);
        }
    }

    /**
     * 普通文件上传
     * @param string $local 本地文件地址
     * @param string $remote 远程上传地址（包括文件名）
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function upload($local, $remote)
    {
        $content = file_get_contents($local);
        $file_name = basename($local);
        $target_path = Tool::getAbsolutePath($remote);
        $path = Tool::convertPath($target_path . $file_name);
        $result = OneDrive::uploadByPath($path, $content);
        $response = OneDrive::responseToArray($result);
        $response['code'] == 200 ? $this->info('上传成功!') : $this->warn('上传失败!');
    }

    /**
     * 大文件上传
     * @param string $local 本地文件地址
     * @param string $remote 远程上传地址（包括文件名）
     * @param integer $chuck 分片大小
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function uploadBySession($local, $remote, $chuck = 3276800)
    {
        ini_set('memory_limit', '-1');
        $file_size = OneDrive::readFileSize($local);
        $target_path = Tool::getAbsolutePath($remote);
        $file_name = basename($local);
        $path = trim($target_path, '/') == '' ? ":/{$file_name}:/" : Tool::convertPath($target_path . $file_name);
        $url_request = OneDrive::createUploadSession($path);
        $url_response = OneDrive::responseToArray($url_request);
        if ($url_response['code'] == 200) {
            $url = $url_response['data']['uploadUrl'];
        } else {
            $this->warn('创建上传任务失败，检查文件是否已经存在！');
            exit;
        }
        $this->info("上传文件：{$local}");
        $this->info("上传链接：{$url}");
        $done = false;
        $offset = 0;
        $length = $chuck;
        while (!$done) {
            $retry = 0;
            $res = OneDrive::uploadToSession($url, $local, $offset, $length);
            $response = OneDrive::responseToArray($res);
            if ($response['code'] == 200) {
                $data = $response['data'];
                if (!empty($data['nextExpectedRanges'])) {
                    // 分片上传
                    $this->info("length: {$data['nextExpectedRanges'][0]}");
                    $ranges = explode('-', $data['nextExpectedRanges'][0]);
                    $offset = intval($ranges[0]);
                    $status = @floor($offset / $file_size * 100) . '%';
                    $this->info("success. progress:{$status}");
                    $done = false;
                } elseif (!empty($data['@content.downloadUrl']) || !empty($data['id'])) {
                    // 上传完成
                    $this->info('文件上传成功！');
                    $done = true;
                } else {
                    // 失败重试
                    $retry++;
                    if ($retry <= 3) {
                        $this->warn("重试第{$retry}次，等待10秒重试...");
                        sleep(10);
                    } else {
                        $this->warn('分片上传失败！');
                        OneDrive::deleteUploadSession($url); // 失败删除任务
                        break;
                    }
                }
            } else {
                $this->warn('分片上传失败！');
                OneDrive::deleteUploadSession($url); // 失败删除任务
                break;
            }
        }
    }
}
