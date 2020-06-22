<?php 

Class ApiController extends AbstractController {

    public function tuiaFarmAction () {
        //tuia_farm
        // userId 用户 id，用户在媒体下的唯一识别信息，来源 于活劢链接中的 &userId=xxx，由媒体拼接提 供
        // timestamp 时间戳，系统当前毫秒数
        // prizeFlag 请求充值的虚拟商品在对接方媒体系统内的标识 符，用于标识具体的虚拟商品，具体由媒体提供
        // orderId  推啊订单号，具有唯一性，幂等由媒体保障
        // appKey 媒体信息
        // sign 签名
        // score 如果充值的是数值类型的虚拟商品，则同时请求 充值对应的数值score，比如积分、金币等
        // reason 充值理由
        if (isset($_POST['userId']) && isset($_POST['timestamp']) && isset($_POST['prizeFlag']) && isset($_POST['orderId']) && isset($_POST['appKey']) && isset($_POST['sign']) && isset($_POST['score']) && isset($_POST['reason'])) {
            //时效性验证
            if (!$_POST['timestamp'] || abs($_POST['timestamp'] - time() * 1000) > 1000 * 60 * 5) {
                $return = array('code' => '602', 'msg' => '验证时效性失败', 'orderId' => $_POST['orderId'], 'extParam' => array('deviceId' => '', 'userId' => $_POST['userId']));
                return json_encode($return);
            }
            if ('2i6pkgFrvhovviEgjBxZT3e5beS9' != $_POST['appKey']) {
                $return = array('code' => '603', 'msg' => '验证appKey失败', 'orderId' => $_POST['orderId'], 'extParam' => array('deviceId' => '', 'userId' => $_POST['userId']));
                return json_encode($return);
            }
            //签名验证
            if (md5($_POST['timestamp'] . $_POST['prizeFlag'] . $_POST['orderId'] . $_POST['appKey'] . '3WkzTvoaCAKQ9cRNHzRgCtHtf6PWsFtNQRrmQpt') != $_POST['sign']) {
                $return = array('code' => '604', 'msg' => '验证签名失败', 'orderId' => $_POST['orderId'], 'extParam' => array('deviceId' => '', 'userId' => $_POST['userId']));
                return json_encode($return);
            }
            $sql = 'SELECT user_id, imei FROM t_user WHERE device_id = ?';
            $userInfo = $this->db->getRow($sql, $_POST['userId']);
            if (!$userInfo) {
                $return = array('code' => '605', 'msg' => '无效用户', 'orderId' => $_POST['orderId'], 'extParam' => array('deviceId' => '', 'userId' => $_POST['userId']));
                return json_encode($return);
            }
            //插入访问日志
            $sql = 'INSERT INTO t_api_log (`source`, `type`, `order_id`, `user_id`, `params`) SELECT :source, :type, :order_id, :user_id, :params FROM DUAL WHERE NOT EXISTS (SELECT log_id FROM t_api_log WHERE source = :source AND order_id = :order_id)';
            $result = $this->db->exec($sql, array('source' => 'tuia', 'type' => 'tuia_farm', 'order_id' => $_POST['orderId'], 'user_id' => $userInfo['user_id'], 'params' => json_encode($_POST)));
            if ($result) {
                //添加金币
                $sql = 'INSERT INTO t_gold SET user_id = :user_id, change_gold = :change_gold, gold_source = :gold_source, change_type = :change_type, relation_id = :relation_id, change_date = :change_date';
                $this->db->exec($sql, array('user_id' => $userInfo['user_id'], 'change_gold' => $_POST['score'], 'gold_source' => 'tuia_farm', 'change_type' => 'in', 'relation_id' => $this->db->lastInsertId(), 'change_date' => date('Y-m-d')));
                //返回数据
                $return = array('code' => '0', 'msg' => '', 'orderId' => $_POST['orderId'], 'extParam' => array('deviceId' => $userInfo['imei'], 'userId' => $_POST['userId']));
                return json_encode($return);
            } else {
                //返回数据
                $return = array('code' => '606', 'msg' => '不能重复添加', 'orderId' => $_POST['orderId'], 'extParam' => array('deviceId' => $userInfo['imei'], 'userId' => $_POST['userId']));
                return json_encode($return);
            }
        } else {
            //code “0”:成功，“-1”:重填，“其他”:充值异 常。注意:响应 code 类型需为 String
            //msg 充值失败信息
            //orderId 推啊订单号 String
            // extParam 用户设备id，Android:imei;ios:idfa 用户id:用户唯一标识
            //{ "deviceId":"867772035090410", "userId":"123456"
            //}
            $return = array('code' => '601', 'msg' => '缺少参数', 'orderId' => $_POST['orderId'] ?? '', 'extParam' => array('deviceId' => '', 'userId' => $_POST['userId'] ?? ''));
            return json_encode($return);
        }
    }


    public function yuwanBoxAction () {
        //yuwan
//        参数名	必选	类型	说明
        //orderNo	是	string	新量象平台唯一订单号
        //rewardDataJson	是	string	领取奖励信息（json_encode）
        //sign	是	string	签名
        //time	是	int	发送时 时间戳 (单位秒)

//        rewardDataJson参数名	必选	类型	说明
//advertName	是	string	广告名称
//rewardRule	是	string	用户领取奖励规则标题
//stageId	是	int	广告期数id
//stageNum	是	string	广告期数信息
//advertIcon	是	string	广告icon
//rewardType	是	string	1:试玩 2:充值 3.冲刺奖励 4:注册奖励 5:奖励卡奖励(全额给用户)
//isSubsidy	是	int	0 否 1 是 新量象平台补贴
//mediaMoney	是	float	媒体方可获取的金额，单位元
//rewardUserRate	是	float	领取时媒体设置的用户奖励比
//currencyRate	是	float	媒体设置的媒体币兑换比率
//userMoney	是	float	用户领取的金额, 单位元
//userCurrency	是	float	用户领取的媒体币，(userCurrency = userMoney * currencyRate)
//mediaUserId	是	int	媒体方登录用户ID
//receivedTime	是	int	奖励收取时间 (时间戳，单位秒)
        if (isset($_POST['orderNo']) && isset($_POST['rewardDataJson']) && isset($_POST['sign']) && isset($_POST['time'])) {
            //时效性验证
            if (!$_POST['time'] || abs($_POST['time'] - time()) > 1000 * 60 * 5) {
                $return = array('code' => '702', 'msg' => '验证时效性失败');
                return json_encode($return);
            }
            //签名验证
            if (md5($_POST['rewardDataJson'] . $_POST['time'] . '5sddovjriiay7q897nsuccc7gvntcj9z') != $_POST['sign']) {
                $return = array('code' => '704', 'msg' => '验证签名失败');
                return json_encode($return);
            }
            $sql = 'SELECT user_id, imei FROM t_user WHERE device_id = ?';
            $userInfo = $this->db->getRow($sql, $_POST['rewardDataJson']['mediaUserId'] ?? '');
            if (!$userInfo) {
                $return = array('code' => '705', 'msg' => '无效用户');
                return json_encode($return);
            }
            //插入访问日志
            $sql = 'INSERT INTO t_api_log (`source`, `type`, `order_id`, `user_id`, `params`) SELECT :source, :type, :order_id, :user_id, :params FROM DUAL WHERE NOT EXISTS (SELECT log_id FROM t_api_log WHERE source = :source AND order_id = :order_id)';
            $result = $this->db->exec($sql, array('source' => 'yuwan', 'type' => 'yuwan_box', 'order_id' => $_POST['orderNo'], 'user_id' => $userInfo['user_id'], 'params' => json_encode($_POST)));
            if ($result) {
                //添加金币
                $sql = 'INSERT INTO t_gold SET user_id = :user_id, change_gold = :change_gold, gold_source = :gold_source, change_type = :change_type, relation_id = :relation_id, change_date = :change_date';
                $this->db->exec($sql, array('user_id' => $userInfo['user_id'], 'change_gold' => $_POST['rewardDataJson']['userCurrency'] ?? 0, 'gold_source' => 'yuwan_box', 'change_type' => 'in', 'relation_id' => $this->db->lastInsertId(), 'change_date' => date('Y-m-d')));
                //返回数据
                $return = array('code' => '0', 'msg' => '');
                return json_encode($return);
            } else {
                //返回数据
                $return = array('code' => '706', 'msg' => '不能重复添加');
                return json_encode($return);
            }
        } else {
            //code “0”:成功，“-1”:重填，“其他”:充值异 常。注意:响应 code 类型需为 String
            //msg 充值失败信息
            //orderId 推啊订单号 String
            // extParam 用户设备id，Android:imei;ios:idfa 用户id:用户唯一标识
            //{ "deviceId":"867772035090410", "userId":"123456"
            //}
            $return = array('code' => '701', 'msg' => '缺少参数');
            return json_encode($return);
        }
    }

}