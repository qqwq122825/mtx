<?php
declare(strict_types=1);
namespace MTX;
/** Plain-text templates; changing these never changes the bot binding or reply routes. */
final class TelegramReplies
{
    public const COOLDOWN = 1800;
    public const LABELS = ['welcome'=>'开始欢迎语','consult'=>'项目咨询','install'=>'安装帮助','support'=>'售后反馈','received'=>'消息收到提示'];
    public const BUTTONS = ['💬 项目咨询'=>'consult','🛠 安装帮助'=>'install','📮 售后反馈'=>'support'];
    public static function defaults(): array
    {
        return [
            'welcome'=>"你好，这里是满天星 ✨\n请直接发送你的问题、图片或文件，看到消息后我会尽快回复你。\n\n也可以点击下方按钮，选择咨询类型。",
            'consult'=>"💬 项目咨询\n请告诉我你想了解的游戏或项目，以及具体问题，看到消息后我会回复你。",
            'install'=>"🛠 安装帮助\n请发送游戏名称、设备型号、iOS 版本和报错截图，方便我尽快排查。",
            'support'=>"📮 售后反馈\n请描述遇到的问题，并附上相关截图。看到消息后我会尽快处理。\n请勿发送密码、验证码或完整付款资料。",
            'received'=>'✅ 消息已收到，看到后我会尽快回复你，请耐心等待。',
        ];
    }
    public static function read(array $state): array
    {
        $values=self::defaults();
        foreach ($values as $key=>$default) {
            $text=$state['auto_replies'][$key]??null;
            if (is_string($text) && trim($text)!=='' && mb_check_encoding($text,'UTF-8') && mb_strlen($text)<=1000) $values[$key]=$text;
        }
        return $values;
    }
    public static function validate(array $input): array
    {
        $values=[];
        foreach (self::LABELS as $key=>$label) {
            $text=trim(str_replace("\r\n","\n",Http::text($input,$key,1000)));
            if ($text==='') throw new Problem(422,$label.'请填写 1–1000 个字。');
            $values[$key]=$text;
        }
        return $values;
    }
    public static function keyboard(): array
    {
        $buttons=array_map(fn($text)=>['text'=>$text],array_keys(self::BUTTONS));
        return ['keyboard'=>[array_slice($buttons,0,2),array_slice($buttons,2)],'resize_keyboard'=>true,'one_time_keyboard'=>true,'input_field_placeholder'=>'直接发送问题，或选择咨询类型'];
    }
}
