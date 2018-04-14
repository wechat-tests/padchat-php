<?php
/**
 * Created by PhpStorm.
 * User: Mr.Zhou
 * Date: 2018/4/11
 * Time: 下午4:42
 */

namespace Padchat;

use Padchat\Core\Ioc as PadchatDi;
use Padchat\Core\TaskIoc;
use Padchat\Core\Receive;

class Callback
{
    private $webSocket;

    public function __construct()
    {

    }

    /**
     * 获取登录二维码成功
     * @param $ret
     */
    private function qrcodeSuccessHandler($ret)
    {
        PadchatDi::getDefault()->get('client')->setLoginQrcode($ret->qr_code);
        file_put_contents(BASE_PATH . '/runtime/qrcode/login-' . date('His') . '.png', base64_decode($ret->qr_code));
    }

    /**
     * 获取登录状态
     * @param $ret
     */
    private function loginStatusHandler($ret)
    {
        PadchatDi::getDefault()->get('client')->setLoginStatus($ret);
    }

    /**
     * 登录成功
     * 设置全局微信id
     * 还可以通过这个wxid载入一些数据库的配置信息
     * @param $ret
     */
    private function loginSuccessHandler($ret)
    {
        TaskIoc::getDefault()->set('wxid', $ret->user_name);
        TaskIoc::getDefault()->set('filter_wxid', ['wxid_k9jdv2j4n8cf12', '5339539252@chatroom']);
        PadchatDi::getDefault()->get('client')->setLoginSuccessInfo($ret);
        PadchatDi::getDefault()->get('client')->setWxInfo($ret->user_name, $ret);
        swoole_timer_tick(10, function () {
            PadchatDi::getDefault()->get('client')->msgTask(TaskIoc::getDefault()->get('wxid'));
        });
    }

    /**
     * 获取好友信息处理
     * 可存入到redis,或者数据库
     * 可通过数据的特性去判断群，还是好友或者公众号、或者群成员列表数据
     * @param $ret
     */
    private function friendListHandler($ret)
    {
        PadchatDi::getDefault()->get('client')->setFriendList(TaskIoc::getDefault()->get('wxid'), $ret);
    }

    /**
     * 收到好友申请，可自动操作。
     * 自动通过验证、发送消息，发送群邀请等
     * @param $ret
     * @param array $params
     */
    private function friendRequestHandler($ret, array $params)
    {
        if ($params['content'] == 'test') {
            PadchatDi::getDefault()->get('api')->acceptUser($params['encryptusername'], $params['ticket']);
        }
    }

    public function handle()
    {
        //初始化设备
        if (PadchatDi::getDefault()->get('receive')->isInit()) {
            PadchatDi::getDefault()->get('api')->login();
        }
        //获取登录二维码成功
        if ($ret = PadchatDi::getDefault()->get('receive')->isGetQrcodeSuccess()) {
            $this->qrcodeSuccessHandler($ret);
        }
        //等待扫码
        if ($ret = PadchatDi::getDefault()->get('receive')->isWaitScan()) {
            $this->loginStatusHandler(['status' => 0, 'nickname' => '', 'head_url' => '']);
        }
        //等待确认
        if ($ret = PadchatDi::getDefault()->get('receive')->isWaitConfirm()) {
            $this->loginStatusHandler(['status' => 1, 'nickname' => $ret->nick_name, 'head_url' => $ret->head_url]);
        }
        //登录成功
        if ($ret = PadchatDi::getDefault()->get('receive')->isLoginSuccess()) {
            $this->loginSuccessHandler($ret);
        }
        //获取好友列表回调
        if ($ret = PadchatDi::getDefault()->get('receive')->isGetFriendsList()) {
            $this->friendListHandler($ret);
        }
        //消息事件回调
        if ($ret = PadchatDi::getDefault()->get('receive')->isMessageCallback()) {
            $this->message($ret);
        }
    }

    /**
     * // 1  文字消息
     * // 2  好友信息推送，包含好友，群，公众号信息
     * // 3  收到图片消息
     * // 34  语音消息
     * // 35  用户头像buf
     * // 37  收到好友请求消息
     * // 42  名片消息
     * // 43  视频消息
     * // 47  表情消息
     * // 48  定位消息
     * // 49  APP消息(文件 或者 链接 H5)
     * // 50  语音通话
     * // 51  状态通知（如打开与好友/群的聊天界面）
     * // 52  语音通话通知
     * // 53  语音通话邀请
     * // 62  小视频
     * // 2000  转账消息
     * // 2001  收到红包消息
     * // 3000  群邀请
     * // 9999  系统通知
     * // 10000  微信通知信息. 微信群信息变更通知，多为群名修改，进群，离群信息，不包含群内聊天信息
     * // 10002  撤回消息
     * 收到消息处理机制
     * @param $data
     */
    private function message($data)
    {
        /** wxid白名单过滤 */
        if (TaskIoc::getDefault()->get('filter_wxid') && !in_array($data->from_user, TaskIoc::getDefault()->get('filter_wxid'))) {
            return;
        }
        /** 群消息如果是非@消息直接绕过 */
        if ($params = PadchatDi::getDefault()->get('receive')->getMessageParams($data)) {
            if ($params['msg_from'] == 2 && !PadchatDi::getDefault()->get('receive')->isAtMe()) {
                return;
            }
        }

        switch ($data->sub_type) {
            case 1:
                PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到文字消息");
                break;
            case 3:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到图片消息");
                break;
            case 34:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收语音消息");
                break;
            case 35:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到头像BUFF消息");
                break;
            case 37:
                $this->friendRequestHandler($data, PadchatDi::getDefault()->get('receive')->getFriendRequestParams($data));
                break;
            case 42:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到名片消息");
                break;
            case 43:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到视频消息");
                break;
            case 47:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到表情消息");
                break;
            case 48:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到定位消息");
                break;
            case 49:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到APP消息");
                break;
            case 50:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到语音通话消息");
                break;
            case 51:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到状态通知消息");
                break;
            case 52:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到通话通知消息");
                break;
            case 53:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到通话邀请消息");
                break;
            case 62:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到小视频消息");
                break;
            case 2000:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到转账消息");
                break;
            case 2001:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到红包消息");
                break;
            case 3000:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到群邀请消息");
                break;
            case 9999:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到系统通知消息");
                break;
            case 10000:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到微信通知信息消息");
                break;
            case 10002:
                //PadchatDi::getDefault()->get('api')->sendMsg($data->from_user, "收到撤回消息");
                break;
        }
    }
}