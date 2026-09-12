<?php
declare(strict_types=1);
namespace MTX;
/** Plain-text templates; changing these never changes the bot binding or reply routes. */
final class TelegramReplies
{
    public const COOLDOWN = 1800;
    public const LABELS = ['welcome'=>'开始欢迎语','question'=>'问题咨询','cooperation'=>'合作咨询','received'=>'消息收到提示'];
    public const BUTTONS = ['💬 问题咨询'=>'question','🤝 合作咨询'=>'cooperation'];
    public static function defaults(): array
    {
        return [
            'welcome'=>"你好，这里是满天星 ✨\n请直接发送你的问题、图片或文件，看到消息后我会尽快回复你。\n\n也可以点击下方按钮，选择咨询类型。",
            'question'=>"💬 问题咨询\n请直接描述你遇到的问题，也可以附上图片或文件。看到消息后我会尽快回复你。",
            'cooperation'=>"🤝 合作咨询\n请简单介绍你的合作方向和具体需求，看到消息后我会尽快与你沟通。",
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
        $buttons=[];
        foreach (self::BUTTONS as $text=>$field) $buttons[]=['text'=>$text,'callback_data'=>'mtx:reply:'.$field];
        return ['inline_keyboard'=>array_chunk($buttons,2)];
    }
}
