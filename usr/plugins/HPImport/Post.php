<?php

class HPImport_Post extends Widget_Abstract_Contents implements Widget_Interface_Do {
    public function __construct($request, $response, $params = NULL) {
        parent::__construct($request, $response, $params);
    }

    public function die_with_json($code,$msg){
        $array = array(
            'ret'=>$code,
            'msg'=>$msg
        );
        die(json_encode($array));
    }

    /**
     * 绑定动作
     *
     * @access public
     * @return void
     */
    public function action() {
        $request = Typecho_Request::getInstance();
//        //TODO 检查IP合法性
//        if($request->getIp() != '127.0.0.1'){
//            $this->die_with_json(1001, '请从本地请求服务');
//        }
        //判断提交方式
        if(!$request->isPost()){
            $this->die_with_json(1002, '请使用post方式提交,避免数据超长被截断');
        }

        @$settings = Helper::options()->plugin('HPImport');
        if(!$settings) $this->die_with_json(1003, '未开启Typecho插件，无法查到导入数据所使用的账号密码');


        $auth_key = $settings->import_user_auth;
        $key = $request->get('_auth','xxx');
        if(empty($auth_key) || empty($key) || $auth_key !== $key){
            $this->die_with_json(false, 'Invalid auth key');
        }



        $user_info = $settings->import_user_info;
        list($user,$password) = explode('/',$user_info);
        if(empty($user) or empty($password)){
            $this->die_with_json(1004, '未设置用户名和密码');
        }

        //判断登陆状态
        if (!$this->user->hasLogin()) {
            if (!$this->user->login($user, $password, true)) { //使用特定的账号登陆
                $this->die_with_json(1005,'登录失败');
            }
        }

        //获取提交的数据
        /** 提交的数据格式：
        //  category: 新闻分类,字符串形式
        //  title: 新闻标题,
        //  content: html_encode_b64,新闻内容,
        //  author: 来源作者,
        //  referer: 来源网址,
        //  date: 发表时间,标准格式,如 2016-11-11 23:33:11
        //  keywords: 可选。关键词,如果有则保存起来
         */
        $category = $request->get('category','默认分类');
        $title = $request->get('title');
        $content = $request->get('content');
        $author = $request->get('author','佚名');
        $referer = $request->get('referer','');
        $date = $request->get('date','');
        $keywords= $request->get('keywords','');
		$tags= $request->get('tags','');

        if(empty($title) || empty($content)){
            $this->die_with_json(1006,'title和content必须非空');
        }

        $category_mgr = $this->widget('Widget_Metas_Category_Edit');
        //判断分类名称是否存在
        if($category_mgr->nameExists($category)){ //注意:nameExists方法,用于判断是否不存在,TE命名规范有问题,这里需要注意一下。
            //没有则需要新建
            $row = array();
            $row['name'] = $category;
            $row['slug'] = Typecho_Common::slugName($category);
            $row['type'] = 'category';
            $row['order'] = $category_mgr->getMaxOrder('category', $category['parent']) + 1;
            $mid = $category_mgr->insert($row);

        }else{
            $db= Typecho_Db::get();
            $row = $db->fetchRow($this->db->select()
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->where('name = ?', $category)->limit(1));
            $mid = $row['mid'];
        }

        if(!isset($mid) || intval(($mid) == 0 )){
            $this->die_with_json(1007,'无法获取分类信息或者插入新的分类');
        }


        $request->setParams(
            array(
                'title'=>$title,
                'text'=>$content,
                'fieldNames'=>array('author','referer','keywords'),
                'fieldTypes'=>array('str','str','str'),
                'fieldValues'=>array($author,$referer,$keywords),
                'cid'=>'',
                'do'=>'publish',
                'markdown'=>'0',
                'date'=>empty($date)?"":$date,
                'category'=>array($mid),
                'tags'=>$tags,
                //'visibility'=>'hidden',
                'visibility'=>'publish',
                'password'=>'',
                'allowComment'=>'0',
                'allowPing'=>'1',
                'allowFeed'=>'1',
                'trackback'=>'',
            )
        );

        //设置token，绕过安全限制
        $security = $this->widget('Widget_Security');
        $request->setParam('_', $security->getToken($this->request->getReferer()));
        //设置时区
        date_default_timezone_set('PRC');

        //执行添加文章操作
        $widgetName = 'Widget_Contents_Post_Edit';
        $reflectionWidget = new ReflectionClass($widgetName);
        if ($reflectionWidget->implementsInterface('Widget_Interface_Do')) {
            $widget = $this->widget($widgetName);
            $widget->writePost(false);
            if($widget->cid){
                $this->die_with_json(0, $widget->cid);
            }else{
                $this->die_with_json(1008, "Failed to import database");
            }
            return;
        }else{
            $this->die_with_json(1009, "Unknown error");
        }
    }
}

?>
