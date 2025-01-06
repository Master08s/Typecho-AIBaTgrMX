<?php

/**
 * AIBaTgrMX 是一个多功能AI助手插件，包含文章摘要、标签生成、分类推荐、SEO优化
 *
 * @package AIBaTgrMX
 * @author Looks
 * @version 2.2
 * @link https://blog.tgrmx.cn
 */
abstract class AIException extends Exception
{
    protected $type;
    protected $level;
    
    public function __construct($message, $type = 'system', $level = 'error')
    {
        parent::__construct($message);
        $this->type = $type;
        $this->level = $level;
    }
    
    public function getType()
    {
        return $this->type;
    }
    
    public function getLevel()
    {
        return $this->level;
    }
}

class APIException extends AIException
{
    public function __construct($message)
    {
        parent::__construct($message, 'api', 'error');
    }
}

class ValidationException extends AIException
{
    public function __construct($message)
    {
        parent::__construct($message, 'validation', 'warning');
    }
}

class TaskProcessor
{
    private $queue = array();
    private $results = array();
    private $maxConcurrent;
    
    public function __construct($maxConcurrent = 3)
    {
        $this->maxConcurrent = $maxConcurrent;
    }
    
    public function addTask($type, $data, $priority = 0)
    {
        $this->queue[] = array(
            'type' => $type,
            'data' => $data,
            'priority' => $priority,
            'status' => 'pending'
        );
        return $this;
    }
    
    public function processTasks()
    {
        // 按优先级排序
        usort($this->queue, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        
        $running = array();
        $completed = array();
        
        while (!empty($this->queue) || !empty($running)) {
            // 填充运行队列
            while (count($running) < $this->maxConcurrent && !empty($this->queue)) {
                $task = array_shift($this->queue);
                $task['started'] = time();
                $running[] = $task;
                
                // 启动任务处理
                $this->startTask($task);
            }
            
            // 检查完成的任务
            foreach ($running as $key => $task) {
                if ($this->isTaskComplete($task)) {
                    $completed[] = $task;
                    unset($running[$key]);
                }
            }
            
            // 避免CPU过载
            if (!empty($running)) {
                usleep(100000); // 100ms
            }
        }
        
        return $completed;
    }
    
    private function startTask($task)
    {
        try {
            switch ($task['type']) {
                case 'summary':
                    $this->results[$task['id']] = self::generateSummary($task['data']);
                    break;
                case 'tags':
                    $this->results[$task['id']] = self::generateTags($task['data']);
                    break;
                case 'seo':
                    $this->results[$task['id']] = self::optimizeSEO($task['data']);
                    break;
            }
        } catch (Exception $e) {
            $this->results[$task['id']] = array(
                'error' => true,
                'message' => $e->getMessage()
            );
        }
    }
}

class AIBaTgrMX_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        // 注册文章相关钩子
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('AIBaTgrMX_Plugin', 'customExcerpt');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->write = array('AIBaTgrMX_Plugin', 'beforePublish');
        Typecho_Plugin::factory('Widget_Archive')->header = array('AIBaTgrMX_Plugin', 'optimizeSEO');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('AIBaTgrMX_Plugin', 'formatContent');

        // 创建数据表
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        
        try {
            $adapter = $db->getAdapterName();
            if (strpos($adapter, 'Mysql') !== false) {
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}ai_content` (
                    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                    `cid` int(10) unsigned NOT NULL,
                    `type` varchar(20) NOT NULL,
                    `content` text,
                    `created` int(10) unsigned DEFAULT '0',
                    PRIMARY KEY (`id`),
                    KEY `cid` (`cid`),
                    KEY `type_created` (`type`, `created`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } else {
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}ai_content` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `cid` INTEGER NOT NULL,
                    `type` varchar(20) NOT NULL,
                    `content` text,
                    `created` INTEGER DEFAULT '0'
                )");
                $db->query("CREATE INDEX IF NOT EXISTS `{$prefix}ai_content_cid` ON `{$prefix}ai_content` (`cid`)");
                $db->query("CREATE INDEX IF NOT EXISTS `{$prefix}ai_content_type_created` ON `{$prefix}ai_content` (`type`, `created`)");
            }
            return _t('插件启用成功');
        } catch (Exception $e) {
            throw new Typecho_Plugin_Exception(_t('插件启用失败：%s', $e->getMessage()));
        }
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        return _t('插件禁用成功');
    }
    
    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // API 提供商配置
        $provider = new Typecho_Widget_Helper_Form_Element_Select(
            'provider',
            array(
                'deepseek' => _t('DeepSeek API'),
                'openai' => _t('OpenAI API'),
                'custom' => _t('自定义 API')
            ),
            'deepseek',
            _t('API 提供商'),
            _t('选择要使用的 API 服务提供商')
        );
        $form->addInput($provider);

        // 自定义 API 地址
        $customApiUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'customApiUrl',
            NULL,
            '',
            _t('自定义 API 地址'),
            _t('当选择"自定义 API"时，请输入完整的API接口地址')
        );
        $form->addInput($customApiUrl);

        // API 密钥
        $keyValue = new Typecho_Widget_Helper_Form_Element_Text(
            'keyValue',
            NULL,
            '',
            _t('API 密钥'),
            _t('输入您的 API 密钥')
        );
        $form->addInput($keyValue);

        // 作者博客标识
        $blogIdentifier = new Typecho_Widget_Helper_Form_Element_Text(
            'blogIdentifier',
            NULL,
            'blog.tgrmx.cn',
            _t('作者博客标识'),
            _t('请勿修改此项，用于插件功能的正常使用')
        );
        $form->addInput($blogIdentifier);

        // AI 模型选择
        $modelName = new Typecho_Widget_Helper_Form_Element_Select(
            'modelName',
            array(
                'deepseek-chat' => _t('DeepSeek Chat'),
                'gpt-4' => _t('GPT-4'),
                'gpt-4-turbo' => _t('GPT-4 Turbo'),
                'gpt-3.5-turbo' => _t('GPT-3.5 Turbo'),
                'gpt-3.5-turbo-16k' => _t('GPT-3.5 Turbo 16K'),
                'custom' => _t('自定义模型')
            ),
            'deepseek-chat',
            _t('AI 模型'),
            _t('选择要使用的 AI 模型')
        );
        $form->addInput($modelName);

        // 自定义模型名称
        $customModel = new Typecho_Widget_Helper_Form_Element_Text(
            'customModel',
            NULL,
            '',
            _t('自定义模型名称'),
            _t('当选择"自定义模型"时，请输入完整的模型名称')
        );
        $form->addInput($customModel);

        // 功能开关
        $features = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'features',
            array(
                'summary' => _t('自动生成文章摘要'),
                'tags' => _t('自动生成标签')
            ),
            array('summary'),
            _t('启用功能'),
            _t('选择要启用的 AI 功能')
        );
        $form->addInput($features);

        // 摘要长度
        $maxLength = new Typecho_Widget_Helper_Form_Element_Text(
            'maxLength',
            NULL,
            '100',
            _t('摘要长度'),
            _t('自动生成摘要的最大字数')
        );
        $form->addInput($maxLength);

        // 标签数量
        $maxTags = new Typecho_Widget_Helper_Form_Element_Text(
            'maxTags',
            NULL,
            '5',
            _t('标签数量'),
            _t('自动生成的标签数量上限（1-10）')
        );
        $form->addInput($maxTags);

        // 输出语言
        $language = new Typecho_Widget_Helper_Form_Element_Select(
            'language',
            array(
                'zh' => _t('中文'),
                'en' => _t('英文'),
                'ja' => _t('日语'),
                'ko' => _t('韩语'),
                'fr' => _t('法语'),
                'de' => _t('德语'),
                'es' => _t('西班牙语'),
                'ru' => _t('俄语')
            ),
            'zh',
            _t('输出语言'),
            _t('选择 AI 生成内容的语言')
        );
        $form->addInput($language);

        // 缓存时间
        $cacheTime = new Typecho_Widget_Helper_Form_Element_Text(
            'cacheTime',
            NULL,
            '86400',
            _t('缓存时间'),
            _t('AI生成内容的缓存时间（秒），默认24小时')
        );
        $form->addInput($cacheTime);

        // 默认分类
        $defaultCategory = new Typecho_Widget_Helper_Form_Element_Text(
            'defaultCategory',
            NULL,
            '默认分类',
            _t('默认分类'),
            _t('当无法确定合适分类时使用的默认分类名称')
        );
        $form->addInput($defaultCategory);

        // 摘要生成提示词
        $summaryPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'summaryPrompt',
            NULL,
            self::getDefaultSummaryPrompt(),
            _t('摘要生成提示词'),
            _t('用于生成文章摘要的系统提示词，支持变量：{{LANGUAGE}}、{{MAX_LENGTH}}')
        );
        $form->addInput($summaryPrompt);

        // 标签生成提示词
        $tagsPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'tagsPrompt',
            NULL,
            self::getDefaultTagsPrompt(),
            _t('标签生成提示词'),
            _t('用于生成文章标签的系统提示词，支持变量：{{LANGUAGE}}、{{MAX_TAGS}}')
        );
        $form->addInput($tagsPrompt);

        // 分类推荐提示词
      /*  $categoryPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'categoryPrompt',
            NULL,
            self::getDefaultCategoryPrompt(),
            _t('分类推荐提示词'),
            _t('用于推荐文章分类的系统提示词，支持变量：{{LANGUAGE}}、{{CATEGORIES}}')
        );
        $form->addInput($categoryPrompt);

        // SEO优化提示词
        $seoPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'seoPrompt',
            NULL,
            self::getDefaultSeoPrompt(),
            _t('SEO优化提示词'),
            _t('用于生成SEO信息的系统提示词，支持变量：{{LANGUAGE}}、{{SEO_LENGTH}}')
        );
        $form->addInput($seoPrompt);*/

        // 添加配置面板的样式和脚本
        echo '<style>
            #typecho-option-item-customApiUrl-1,
            #typecho-option-item-customModel-5 {
                display: none;
            }
        </style>';
        
        echo '<script>
        function updateVisibility() {
            var provider = document.getElementsByName("provider")[0];
            var model = document.getElementsByName("modelName")[0];
            var customApiUrlOption = document.getElementById("typecho-option-item-customApiUrl-1");
            var customModelOption = document.getElementById("typecho-option-item-customModel-5");
            
            if (!provider || !model || !customApiUrlOption || !customModelOption) {
                console.log("Some elements not found");
                return;
            }
            
            customApiUrlOption.style.display = provider.value === "custom" ? "block" : "none";
            customModelOption.style.display = model.value === "custom" ? "block" : "none";
        }

        window.addEventListener("DOMContentLoaded", function() {
            var providerSelect = document.getElementsByName("provider")[0];
            var modelSelect = document.getElementsByName("modelName")[0];
            
            if (providerSelect && modelSelect) {
                providerSelect.addEventListener("change", updateVisibility);
                modelSelect.addEventListener("change", updateVisibility);
                updateVisibility();
            }
        });
        </script>';
    }
    
    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        // 个人用户不需要配置，直接返回
    }

    public static function customExcerpt($excerpt, $widget)
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
            
            // 添加配置检查
            if (!$options || !isset($options->features)) {
                error_log('AIBaTgrMX: 插件配置未找到');
                return $excerpt;
            }
            
            $features = $options->features ? $options->features : array();

            if (!in_array('summary', $features)) {
                return $excerpt;
            }

            $db = Typecho_Db::get();
            $customContent = $db->fetchRow($db->select('str_value')
                ->from('table.fields')
                ->where('cid = ?', $widget->cid)
                ->where('name = ?', 'content'));

            if ($customContent && !empty($customContent['str_value'])) {
                $excerpt = $customContent['str_value'];
            } else {
                $prompt = "请为以下文章生成一个简短的摘要，字数不超过{$options->maxLength}字：\n\n{$widget->text}";
                $summary = self::callApi($prompt, 'summary');

                if (!empty($summary)) {
                    if ($customContent) {
                        $db->query($db->update('table.fields')
                            ->rows(array('str_value' => $summary))
                            ->where('cid = ?', $widget->cid)
                            ->where('name = ?', 'content'));
                    } else {
                        $db->query($db->insert('table.fields')
                            ->rows(array(
                                'cid' => $widget->cid,
                                'name' => 'content',
                                'type' => 'str',
                                'str_value' => $summary,
                                'int_value' => 0,
                                'float_value' => 0
                            )));
                    }
                    $excerpt = $summary;
                }
            }

            if (mb_strlen($excerpt) > $options->maxLength) {
                $excerpt = mb_substr($excerpt, 0, $options->maxLength) . '...';
            }

            return $excerpt;
        } catch (Exception $e) {
            error_log('AI摘要生成错误: ' . $e->getMessage());
            return $excerpt;
        }
    }

    /**
     * 在文章发布前处理
     * 
     * @access public
     * @param array $contents 文章内容
     * @param Widget_Abstract_Contents $obj 文章对象
     * @return array
     */
    public static function beforePublish($contents, $obj)
    {
        try {
            $options = Helper::options()->plugin('AIBaTgrMX');
            $features = $options->features ? $options->features : array();

            // 如果没有启用任何功能，直接返回
            if (empty($features)) {
                error_log('AIBaTgrMX: No features enabled');
                return $contents;
            }

            // 获取文章文本
            $text = $contents['text'];
            error_log('AIBaTgrMX: Processing content length: ' . strlen($text));

            // 分类推荐
            if (in_array('category', $features)) {
                error_log('AIBaTgrMX: Category feature is enabled');
                // 只在没有设置分类或分类为空时进行推荐
                if (empty($contents['category']) || $contents['category'] == '0') {
                    error_log('AIBaTgrMX: No category set, attempting to suggest one');
                    try {
                        $categoryMid = self::suggestCategory($text);
                        error_log('AIBaTgrMX: Suggested category mid: ' . $categoryMid);
                        
                        if (!empty($categoryMid) && is_numeric($categoryMid)) {
                            // 验证分类是否存在
                            $db = Typecho_Db::get();
                            $category = $db->fetchRow($db->select('mid')
                                ->from('table.metas')
                                ->where('type = ?', 'category')
                                ->where('mid = ?', $categoryMid));
                            
                            if ($category) {
                                error_log('AIBaTgrMX: Setting category mid to: ' . $categoryMid);
                                $contents['category'] = strval($categoryMid);
                                $contents['categories'] = array($categoryMid);
                            } else {
                                error_log('AIBaTgrMX: Category mid not found: ' . $categoryMid);
                            }
                        } else {
                            error_log('AIBaTgrMX: Invalid category mid returned: ' . $categoryMid);
                        }
                    } catch (Exception $e) {
                        error_log('AIBaTgrMX: Error suggesting category: ' . $e->getMessage());
                    }
                } else {
                    error_log('AIBaTgrMX: Category already set to: ' . $contents['category']);
                }
            } else {
                error_log('AIBaTgrMX: Category feature is not enabled');
            }

            // 生成标签
            if (in_array('tags', $features)) {
                $tags = self::generateTags($text);
                if (!empty($tags)) {
                    // 如果已有标签，则追加新标签
                    if (!empty($contents['tags'])) {
                        $contents['tags'] .= ',' . $tags;
                    } else {
                        $contents['tags'] = $tags;
                    }
                    error_log('AIBaTgrMX: Generated tags: ' . $tags);
                }
            }

            // 生成摘要
            if (in_array('summary', $features) && empty($contents['excerpt'])) {
                $summary = self::generateSummary($text);
                if (!empty($summary)) {
                    $contents['excerpt'] = $summary;
                    error_log('AIBaTgrMX: Generated summary length: ' . strlen($summary));
                }
            }

            return $contents;
        } catch (Exception $e) {
            error_log('AIBaTgrMX beforePublish error: ' . $e->getMessage());
            return $contents;
        }
    }

    /**
     * 生成标签
     * 
     * @param string $content 文章内容
     * @return string 生成的标签，以逗号分隔
     */
    private static function generateTags($content)
    {
        try {
            $options = Helper::options()->plugin('AIBaTgrMX');
            
            // 调用API生成标签
            $tags = self::callApi($content, 'tags');
            
            // 处理返回的标签
            $tagArray = array_map('trim', explode(',', $tags));
            
            // 过滤空标签和过长的标签
            $tagArray = array_filter($tagArray, function($tag) {
                $length = mb_strlen($tag);
                return $length > 0 && $length <= 30;
            });
            
            // 限制标签数量
            $tagArray = array_slice($tagArray, 0, intval($options->maxTags));
            
            return implode(',', $tagArray);
        } catch (Exception $e) {
            error_log('Generate tags error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 生成摘要
     * 
     * @param string $content 文章内容
     * @return string 生成的摘要
     */
    private static function generateSummary($content)
    {
        try {
            $options = Helper::options()->plugin('AIBaTgrMX');
            
            // 调用API生成摘要
            $summary = self::callApi($content, 'summary');
            
            // 限制摘要长度
            if (mb_strlen($summary) > $options->maxLength) {
                $summary = mb_substr($summary, 0, $options->maxLength) . '...';
            }
            
            return $summary;
        } catch (Exception $e) {
            error_log('Generate summary error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 推荐分类
     * 
     * @param string $content 文章内容
     * @return string|null 推荐的分类mid
     */
    private static function suggestCategory($content)
    {
        try {
            $options = Helper::options()->plugin('AIBaTgrMX');
            error_log('AIBaTgrMX: Starting category suggestion process');
            
            // 获取所有分类
            $db = Typecho_Db::get();
            $categories = $db->fetchAll($db->select('mid', 'name', 'parent')
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->order('order', Typecho_Db::SORT_ASC));
            
            if (empty($categories)) {
                error_log('AIBaTgrMX: No categories found');
                return null;
            }
            
            // 构建分类映射（同时保存原始名称和小写名称）
            $categoryMap = array();
            $categoryNames = array();
            foreach ($categories as $category) {
                $categoryMap[strtolower($category['name'])] = array(
                    'mid' => $category['mid'],
                    'name' => $category['name'],
                    'parent' => $category['parent']
                );
                $categoryNames[] = $category['name'];
            }
            
            error_log('AIBaTgrMX: Available categories: ' . implode(', ', $categoryNames));
            
            // 预处理文章内容
            $content = self::preprocessContentForCategory($content);
            
            // 调用API获取建议的分类
            try {
                $suggestedCategory = self::callApi($content, 'category');
                error_log('AIBaTgrMX: API response for category: ' . $suggestedCategory);
                
                if (empty($suggestedCategory)) {
                    error_log('AIBaTgrMX: Empty category suggestion');
                    return self::getFallbackCategory($options);
                }
                
                // 清理API返回的内容，只保留数字
                $suggestedCategory = trim($suggestedCategory);
                $suggestedCategory = preg_replace('/[^0-9]/', '', $suggestedCategory);
                
                if (!is_numeric($suggestedCategory)) {
                    error_log('AIBaTgrMX: Invalid category format returned: ' . $suggestedCategory);
                    return self::getFallbackCategory($options);
                }
                
                $mid = intval($suggestedCategory);
                
                // 验证分类ID是否存在
                foreach ($categories as $category) {
                    if ($category['mid'] == $mid) {
                        error_log('AIBaTgrMX: Found matching category mid: ' . $mid);
                        
                        // 检查是否有子分类
                        $hasChildren = false;
                        foreach ($categories as $potentialChild) {
                            if ($potentialChild['parent'] == $mid) {
                                $hasChildren = true;
                                break;
                            }
                        }
                        
                        if (!$hasChildren) {
                            error_log('AIBaTgrMX: Using category mid: ' . $mid);
                            return $mid;
                        } else {
                            error_log('AIBaTgrMX: Category has children, searching for best child match');
                            // 获取所有子分类
                            $childCategories = array();
                            foreach ($categories as $potentialChild) {
                                if ($potentialChild['parent'] == $mid) {
                                    $childCategories[] = $potentialChild;
                                }
                            }
                            
                            // 再次调用API，限定在子分类中选择
                            $childCategoryList = array_map(function($cat) {
                                return "{$cat['name']} (mid: {$cat['mid']})";
                            }, $childCategories);
                            
                            $childPrompt = $content . "\n\n只能从以下子分类中选择：\n" . implode("\n", $childCategoryList);
                            $childSuggestion = self::callApi($childPrompt, 'category');
                            
                            if (!empty($childSuggestion)) {
                                $childSuggestion = trim($childSuggestion);
                                $childSuggestion = preg_replace('/[^0-9]/', '', $childSuggestion);
                                
                                if (is_numeric($childSuggestion)) {
                                    $childMid = intval($childSuggestion);
                                    foreach ($childCategories as $child) {
                                        if ($child['mid'] == $childMid) {
                                            error_log('AIBaTgrMX: Using child category mid: ' . $childMid);
                                            return $childMid;
                                        }
                                    }
                                }
                            }
                            
                            // 如果无法选择合适的子分类，返回父分类
                            error_log('AIBaTgrMX: No suitable child category found, using parent: ' . $mid);
                            return $mid;
                        }
                    }
                }
                
                error_log('AIBaTgrMX: Category mid not found in database: ' . $mid);
                return self::getFallbackCategory($options);
                
            } catch (Exception $e) {
                error_log('AIBaTgrMX: API call failed: ' . $e->getMessage());
                return self::getFallbackCategory($options);
            }
            
        } catch (Exception $e) {
            error_log('AIBaTgrMX suggest category error: ' . $e->getMessage());
            error_log('AIBaTgrMX error trace: ' . $e->getTraceAsString());
            return self::getFallbackCategory($options);
        }
    }

    /**
     * 预处理文章内容用于分类推荐
     */
    private static function preprocessContentForCategory($content)
    {
        // 移除HTML标签
        $content = strip_tags($content);
        
        // 提取前3000个字符（通常包含文章主要内容）
        $content = mb_substr($content, 0, 3000);
        
        // 移除多余空白
        $content = preg_replace('/\s+/', ' ', $content);
        
        return trim($content);
    }

    /**
     * 查找最佳匹配的分类
     * 
     * @param string $suggested 建议的分类名称
     * @param array $categories 现有分类列表
     * @return string|null 最佳匹配的分类名称
     */
    private static function findBestCategoryMatch($suggested, $categories)
    {
        if (empty($suggested) || empty($categories)) {
            error_log('AIBaTgrMX: Empty input for category matching');
            return null;
        }

        $suggested = trim($suggested);
        $bestMatch = null;
        $highestSimilarity = 0;
        
        error_log('AIBaTgrMX: Finding best match for category: ' . $suggested);
        
        foreach ($categories as $category) {
            $category = trim($category);
            if (empty($category)) continue;
            
            // 计算相似度
            $similarity = 0;
            
            // 1. 检查完全匹配（区分大小写）
            if ($category === $suggested) {
                error_log('AIBaTgrMX: Found exact case-sensitive match: ' . $category);
                return $category;
            }
            
            // 2. 检查完全匹配（不区分大小写）
            if (strcasecmp($category, $suggested) === 0) {
                error_log('AIBaTgrMX: Found exact case-insensitive match: ' . $category);
                return $category;
            }
            
            // 3. 检查包含关系
            if (mb_stripos($category, $suggested) !== false || 
                mb_stripos($suggested, $category) !== false) {
                $similarity += 0.8;
                error_log('AIBaTgrMX: Found substring match: ' . $category);
            }
            
            // 4. 计算编辑距离相似度
            $levDistance = levenshtein(mb_strtolower($suggested), mb_strtolower($category));
            $maxLength = max(mb_strlen($suggested), mb_strlen($category));
            $levSimilarity = (1 - ($levDistance / $maxLength)) * 0.2;
            $similarity += $levSimilarity;
            
            error_log(sprintf('AIBaTgrMX: Category "%s" similarity: %.2f', $category, $similarity));
            
            if ($similarity > $highestSimilarity) {
                $highestSimilarity = $similarity;
                $bestMatch = $category;
            }
        }
        
        // 只有当相似度超过阈值时才返回匹配结果
        if ($highestSimilarity > 0.6) {
            error_log('AIBaTgrMX: Best match found: ' . $bestMatch . ' with similarity: ' . $highestSimilarity);
            return $bestMatch;
        }
        
        error_log('AIBaTgrMX: No suitable match found. Highest similarity: ' . $highestSimilarity);
        return null;
    }

    /**
     * 获取后备分类
     * 
     * @param object $options 插件配置
     * @return string|null 分类mid
     */
    private static function getFallbackCategory($options)
    {
        error_log('AIBaTgrMX: Getting fallback category');
        
        $db = Typecho_Db::get();
        
        // 首先尝试使用配置中的默认分类
        if (!empty($options->defaultCategory)) {
            $category = $db->fetchRow($db->select('mid')
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->where('name = ?', $options->defaultCategory));
            
            if ($category) {
                error_log('AIBaTgrMX: Using configured default category: ' . $options->defaultCategory);
                return $category['mid'];
            }
        }
        
        // 如果配置的默认分类不存在，尝试使用第一个没有子分类的父分类
        $parentCategory = $db->fetchRow($db->select('mid')
            ->from('table.metas')
            ->where('type = ?', 'category')
            ->where('parent = ?', 0)
            ->order('order', Typecho_Db::SORT_ASC)
            ->limit(1));
        
        if ($parentCategory) {
            error_log('AIBaTgrMX: Using first parent category as fallback');
            return $parentCategory['mid'];
        }
        
        // 如果没有父分类，使用任意分类
        $anyCategory = $db->fetchRow($db->select('mid')
            ->from('table.metas')
            ->where('type = ?', 'category')
            ->order('order', Typecho_Db::SORT_ASC)
            ->limit(1));
        
        if ($anyCategory) {
            error_log('AIBaTgrMX: Using any available category as last resort');
            return $anyCategory['mid'];
        }
        
        error_log('AIBaTgrMX: No fallback category available');
        return null;
    }

    private static function executeTasksConcurrently($tasks)
    {
        $results = array();
        $running = array();
        $mh = curl_multi_init();

        foreach ($tasks as $index => $task) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TCP_NODELAY => 1,
                    CURLOPT_ENCODING => 'gzip,deflate'
                ));
                
                curl_multi_add_handle($mh, $ch);
                $running[$index] = array(
                    'handle' => $ch,
                    'task' => $task
                );
                
            } catch (Exception $e) {
                error_log("Task init failed: " . $e->getMessage());
            }
        }
        
        // 执行并发请求
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
            
            while ($info = curl_multi_info_read($mh)) {
                foreach ($running as $index => $task) {
                    if ($task['handle'] === $info['handle']) {
                        try {
                            $results[$index] = $task['task']();
            } catch (Exception $e) {
                error_log("Task {$index} failed: " . $e->getMessage());
                $results[$index] = null;
                        }
                        curl_multi_remove_handle($mh, $task['handle']);
                        curl_close($task['handle']);
                        unset($running[$index]);
                    }
            }
        }

        } while ($active && $status == CURLM_OK);
        
        curl_multi_close($mh);
        return $results;
    }

    private static function saveField($cid, $name, $value)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select()->from('table.fields')
            ->where('cid = ?', $cid)
            ->where('name = ?', $name));

        if ($row) {
            $db->query($db->update('table.fields')
                ->rows(array('str_value' => $value))
                ->where('cid = ?', $cid)
                ->where('name = ?', $name));
        } else {
            $db->query($db->insert('table.fields')
                ->rows(array(
                    'cid' => $cid,
                    'name' => $name,
                    'type' => 'str',
                    'str_value' => $value,
                    'int_value' => 0,
                    'float_value' => 0
                )));
        }
    }

    public static function optimizeSEO($header, $archive)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
        if (!in_array('seo', $options->features)) {
            return;
        }

        try {
            $seoInfo = self::getOrGenerateSEOInfo($archive);

            // 确保 $seoInfo 是数组并且包含必要的键
            if (is_array($seoInfo) && isset($seoInfo['description']) && isset($seoInfo['keywords'])) {
                $description = htmlspecialchars($seoInfo['description'], ENT_QUOTES, 'UTF-8');
                $keywords = htmlspecialchars($seoInfo['keywords'], ENT_QUOTES, 'UTF-8');

                echo '<meta name="description" content="' . $description . '" />' . "\n";
                echo '<meta name="keywords" content="' . $keywords . '" />' . "\n";
            } else {
                // 使用默认值
                $description = mb_substr($archive->excerpt ?? '', 0, 200);
                $keywords = is_array($archive->tags) ? implode(',', $archive->tags) : ($archive->tags ?? '');

                echo '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
                echo '<meta name="keywords" content="' . htmlspecialchars($keywords, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
            }
        } catch (Exception $e) {
            error_log('AI SEO Error: ' . $e->getMessage());
            // 发生错误时使用默认值
            $description = mb_substr($archive->excerpt ?? '', 0, 200);
            $keywords = is_array($archive->tags) ? implode(',', $archive->tags) : ($archive->tags ?? '');

            echo '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
            echo '<meta name="keywords" content="' . htmlspecialchars($keywords, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
        }
    }

    public static function formatContent($content, $obj)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');

        try {
            if (in_array('format', $options->features)) {
                $content = self::optimizeFormat($content);
            }

            if (in_array('outline', $options->features)) {
                $outline = self::generateOutline($content);
                $content = $outline . $content;
            }

            return $content;
        } catch (Exception $e) {
            error_log('AI Format Error: ' . $e->getMessage());
            return $content;
        }
    }

    /**
     * 准备API请求数据
     * 
     * @param string $prompt 提示词
     * @param string $type 请求类型
     * @param array $options 配置选项
     * @return array
     */
    private static function prepareApiData($prompt, $type, $options = null)
    {
        if (empty($options)) {
            $options = Helper::options()->plugin('AIBaTgrMX');
        }

        $systemPrompt = '';
        switch ($type) {
            case 'summary':
                $systemPrompt = str_replace(
                    array('{{LANGUAGE}}', '{{MAX_LENGTH}}'),
                    array($options->language, $options->maxLength),
                    $options->summaryPrompt
                );
                break;
            case 'tags':
                $systemPrompt = str_replace(
                    array('{{LANGUAGE}}', '{{MAX_TAGS}}'),
                    array($options->language, $options->maxTags),
                    $options->tagsPrompt
                );
                break;
            case 'category':
                // 获取所有分类
                $db = Typecho_Db::get();
                $categories = $db->fetchAll($db->select('mid', 'name', 'parent')
                    ->from('table.metas')
                    ->where('type = ?', 'category')
                    ->order('order', Typecho_Db::SORT_ASC));
                
                if (empty($categories)) {
                    throw new Exception('没有可用的分类列表');
                }
                
                // 构建分类树结构
                $categoryTree = array();
                foreach ($categories as $category) {
                    if ($category['parent'] == 0) {
                        $categoryTree[] = array(
                            'mid' => $category['mid'],
                            'name' => trim($category['name']),
                            'children' => array()
                        );
                    }
                }
                
                // 添加子分类
                foreach ($categories as $category) {
                    if ($category['parent'] != 0) {
                        foreach ($categoryTree as &$parent) {
                            if ($parent['mid'] == $category['parent']) {
                                $parent['children'][] = array(
                                    'mid' => $category['mid'],
                                    'name' => trim($category['name'])
                                );
                            }
                        }
                    }
                }
                
                // 格式化分类列表，确保每个分类都显示其mid和名称
                $categoryList = array();
                foreach ($categoryTree as $category) {
                    $categoryList[] = "{$category['name']} (mid: {$category['mid']})";
                    if (!empty($category['children'])) {
                        foreach ($category['children'] as $child) {
                            $categoryList[] = "    {$child['name']} (mid: {$child['mid']})";
                        }
                    }
                }
                $categoryList = implode("\n", $categoryList);
                
                // 构建用户提示词
                $userPrompt = <<<EOT
请分析以下文章内容，并从给定的分类列表中选择最合适的分类。

文章内容：
{$prompt}

可用的分类列表：
{$categoryList}

重要说明：
1. 只返回一个数字（分类ID/mid），不要返回任何其他内容
2. 不要添加任何前缀、后缀或说明文字
3. 不要返回分类名称，只返回对应的mid数字
4. 如果父分类有子分类，优先从子分类中选择
5. 如果父分类没有子分类，则返回父分类ID
6. 如果无法确定，返回默认分类ID：{$options->defaultCategory}

正确返回示例：
12

错误返回示例：
- 分类ID：12
- [12]技术分享
- 推荐分类12
- 选择分类：12
- 这篇文章属于分类12
- 技术文章
EOT;
                
                // 使用构建的用户提示词
                $prompt = $userPrompt;
                break;
            case 'seo':
                $systemPrompt = str_replace(
                    array('{{LANGUAGE}}', '{{SEO_LENGTH}}'),
                    array($options->language, '160'),
                    $options->seoPrompt
                );
                break;
        }

        // 根据不同类型调整温度参数
        $temperature = 0.7;
        if ($type === 'category') {
            // 分类推荐需要更确定的输出
            $temperature = 0.3;
        }

        $data = array(
            'model' => $options->modelName === 'custom' ? $options->customModel : $options->modelName,
            'messages' => array(
                array('role' => 'system', 'content' => $systemPrompt),
                array('role' => 'user', 'content' => $prompt)
            ),
            'temperature' => $temperature,
            'max_tokens' => 2000,
            'stream' => false
        );

        return $data;
    }

    /**
     * 调用API
     * 
     * @param string $prompt 提示词
     * @param string $type 请求类型
     * @param array $options 配置选项
     * @return string
     * @throws Exception
     */
    private static function callApi($prompt, $type, $options = null)
    {
        try {
            if (empty($options)) {
                $options = Helper::options()->plugin('AIBaTgrMX');
            }
            
            // 准备API请求数据
            $data = self::prepareApiData($prompt, $type, $options);
            
            // 获取API URL
            $apiUrl = self::getApiUrl($options);
            
            // 设置请求头
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $options->apiKey
            );
            
            // 初始化CURL
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 30
            ));
            
            // 发送请求
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // 检查请求是否成功
            if ($httpCode !== 200) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new Exception('API请求失败: ' . $error . ' (HTTP ' . $httpCode . ')');
            }
            
            curl_close($ch);
            
            // 解析响应
            $result = json_decode($response, true);
            if (empty($result) || !isset($result['choices'][0]['message']['content'])) {
                throw new Exception('无效的API响应');
            }
            
            $content = trim($result['choices'][0]['message']['content']);
            
            // 根据不同类型处理响应
            if ($type === 'category') {
                // 获取所有分类
                $db = Typecho_Db::get();
                $categories = $db->fetchAll($db->select('mid')
                    ->from('table.metas')
                    ->where('type = ?', 'category')
                    ->order('order', Typecho_Db::SORT_ASC));
                
                if (empty($categories)) {
                    throw new Exception('没有可用的分类');
                }
                
                // 提取分类mid
                $categoryMids = array_map(function($category) {
                    return $category['mid'];
                }, $categories);
                
                // 清理AI返回的内容，只保留数字
                $content = trim($content);
                $content = preg_replace('/[^0-9]/', '', $content);
                
                // 确保返回的是数字
                if (!is_numeric($content)) {
                    error_log('AIBaTgrMX callApi: Invalid category mid returned: ' . $content);
                    return self::getFallbackCategory($options);
                }
                
                $mid = intval($content);
                
                // 检查返回的分类mid是否在列表中
                if (in_array($mid, $categoryMids)) {
                    error_log('AIBaTgrMX callApi: Found valid category mid: ' . $mid);
                    return $mid;
                }
                
                // 如果没有找到匹配的分类，使用默认分类
                error_log('AIBaTgrMX callApi: No matching category found, using default category');
                return self::getFallbackCategory($options);
            }

            error_log('AIBaTgrMX callApi: Returning final content: ' . $content);
            return $content;
            
        } catch (Exception $e) {
            error_log('AIBaTgrMX callApi error: ' . $e->getMessage());
            error_log('AIBaTgrMX callApi error trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * 获取API URL
     * 
     * @param object $options 配置选项
     * @return string
     */
    private static function getApiUrl($options)
    {
        switch ($options->provider) {
            case 'deepseek':
                return 'https://api.deepseek.com/v1/chat/completions';
            case 'openai':
                return 'https://api.openai.com/v1/chat/completions';
            case 'custom':
                if (empty($options->customApiUrl)) {
                    throw new Exception('自定义API地址不能为空');
                }
                return $options->customApiUrl;
            default:
                throw new Exception('未知的API提供商');
        }
    }

    /**
     * 获取所有分类
     * 
     * @return array
     */
    private static function getCategories()
    {
        try {
            $db = Typecho_Db::get();
            
            // 获取所有分类，包括 mid, name, parent, count 字段
            $categories = $db->fetchAll($db->select('mid', 'name', 'parent', 'count')
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->order('order', Typecho_Db::SORT_ASC));
            
            if (empty($categories)) {
                error_log('AIBaTgrMX: No categories found in database');
                return array();
            }

            // 构建分类树
            $categoryMap = array();
            $categoryNames = array();
            
            // 首先建立分类映射
            foreach ($categories as $category) {
                $categoryMap[$category['mid']] = array(
                    'mid' => $category['mid'],
                    'name' => trim($category['name']),
                    'parent' => $category['parent'],
                    'count' => $category['count'],
                    'children' => array()
                );
            }
            
            // 构建父子关系
            foreach ($categoryMap as $mid => $category) {
                if ($category['parent'] == 0) {
                    // 如果是父级分类
                    if (empty($categoryMap[$mid]['children'])) {
                        // 如果没有子分类，直接添加父分类
                        $categoryNames[] = $category['name'];
                    }
                } else {
                    // 如果是子分类，添加到父分类的children数组中
                    if (isset($categoryMap[$category['parent']])) {
                        $categoryMap[$category['parent']]['children'][$mid] = &$categoryMap[$mid];
                        // 添加子分类名称
                        $categoryNames[] = $category['name'];
                    }
                }
            }
            
            // 确保分类名称唯一且非空
            $categoryNames = array_filter(array_unique($categoryNames), function($name) {
                return !empty(trim($name));
            });
            
            error_log('AIBaTgrMX: Found ' . count($categoryNames) . ' valid categories');
            error_log('AIBaTgrMX: Categories: ' . implode(', ', $categoryNames));
            
            return array_values($categoryNames);
            
        } catch (Exception $e) {
            error_log('AIBaTgrMX getCategories error: ' . $e->getMessage());
            error_log('AIBaTgrMX error trace: ' . $e->getTraceAsString());
            return array();
        }
    }

    private static function checkRateLimit($type)
    {
        static $limits = array(
            'summary' => array('count' => 10, 'period' => 60),
            'tags' => array('count' => 20, 'period' => 60),
            'seo' => array('count' => 30, 'period' => 60)
        );
        
        $key = "rate_limit_{$type}_" . time();
        $count = self::getCache($key) ?: 0;
        
        if ($count >= $limits[$type]['count']) {
            return false;
        }
        
        self::setCache($key, $count + 1, $limits[$type]['period']);
        return true;
    }

    private static function syncApiCall($prompt, $type, $retries)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
        
        // 参数验证
        if (empty($options->keyValue)) {
            throw new Exception('API Key not configured');
        }
        
        // 准备API请求
        $apiUrl = self::getApiUrl($options);
        $data = self::prepareApiData($prompt, $type, $options);
        
        // 执行API调用
        $startTime = microtime(true);
        $result = self::executeApiCall($apiUrl, $data, $options, $retries);
        $responseTime = microtime(true) - $startTime;
        
        // 记录API使用情况
        self::trackApiUsage($type, $responseTime);
        
        // 缓存结果
        self::setCache($cacheKey, $result);
        
        return $result;
    }

    private static function executeApiCall($url, $data, $options, $retries)
    {
                $ch = curl_init();
        $timeout = isset($options->timeout) ? intval($options->timeout) : 30;
        
        $defaultOptions = array(
            CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $options->keyValue,
                        'Content-Type: application/json'
                    ),
                    CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_NODELAY => 1, // 禁用Nagle算法
            CURLOPT_ENCODING => 'gzip,deflate', // 启用压缩
        );
        
        curl_setopt_array($ch, $defaultOptions);
        
        for ($i = 0; $i <= $retries; $i++) {
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response && $httpCode === 200) {
                curl_close($ch);
                return $response;
            }
            
                if ($i < $retries) {
                usleep(pow(2, $i) * 100000); // 指数退避
            }
        }
        
        curl_close($ch);
        throw new Exception('API call failed after ' . $retries . ' retries');
    }

    private static function getDefaultConfig()
    {
        return array(
            'deepseek' => array(
                'url' => 'https://api.deepseek.com',
                'endpoint' => '/v1/chat/completions'
            ),
            'openai' => array(
                'url' => 'https://api.openai.com',
                'endpoint' => '/v1/chat/completions'
            ),
            'custom' => array(
                'url' => '',
                'endpoint' => ''
            )
        );
    }

    private static function callDeepSeekApi($apiUrl, $model, $systemPrompt, $prompt, $key, $options)
    {
        $data = array(
            "model" => $model,
            "messages" => array(
                array("role" => "system", "content" => $systemPrompt),
                array("role" => "user", "content" => $prompt)
            ),
            "stream" => false,
            "temperature" => floatval($options->temperature ?? 0.7)
        );

        $headers = array(
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json'
        );

        return self::makeApiRequest($apiUrl, $data, $headers);
    }

    private static function callOpenAIApi($apiUrl, $model, $systemPrompt, $prompt, $key, $options)
    {
        $data = array(
            "model" => $model,
            "messages" => array(
                array("role" => "system", "content" => $systemPrompt),
                array("role" => "user", "content" => $prompt)
            ),
            "temperature" => floatval($options->temperature ?? 0.7)
        );

        $headers = array(
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json'
        );

        return self::makeApiRequest($apiUrl, $data, $headers);
    }

    private static function makeApiRequest($url, $data, $headers)
    {
        $maxRetries = 3;
        $retryDelay = 1;

        for ($i = 0; $i <= $maxRetries; $i++) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false
                ));

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if (curl_errno($ch)) {
                    throw new Exception(curl_error($ch));
                }

                curl_close($ch);

                if ($httpCode !== 200) {
                    throw new Exception("HTTP Error: " . $httpCode . "\nResponse: " . $response);
                }

                $result = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Invalid JSON response");
                }

                if (isset($result['choices'][0]['message']['content'])) {
                    return trim($result['choices'][0]['message']['content']);
                }

                throw new Exception("Unexpected response format");

            } catch (Exception $e) {
                if ($i === $maxRetries) {
                    throw new Exception('API call failed after ' . $maxRetries . ' retries: ' . $e->getMessage());
                }
                error_log('API call attempt ' . ($i + 1) . ' failed: ' . $e->getMessage());
                sleep($retryDelay * ($i + 1));
            }
        }
    }

    private static function handleCache($cid, $type, $callback)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
        $cacheTime = intval($options->cacheTime);

        if ($cacheTime < 0) {
            return $callback();
        }

        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();

            if ($cacheTime == 0) {
                $cache = $db->fetchRow($db->select()
                    ->from('table.ai_content')
                    ->where('cid = ?', $cid)
                    ->where('type = ?', $type));

                if ($cache) {
                    return $cache['content'];
                }
            }

            $cache = $db->fetchRow($db->select()
                ->from('table.ai_content')
                ->where('cid = ?', $cid)
                ->where('type = ?', $type)
                ->where('created > ?', time() - $cacheTime));

            if ($cache) {
                return $cache['content'];
            }

            $content = $callback();

            $db->query($db->delete('table.ai_content')
                ->where('cid = ?', $cid)
                ->where('type = ?', $type));

            $db->query($db->insert('table.ai_content')->rows(array(
                'cid' => $cid,
                'type' => $type,
                'content' => $content,
                'created' => time()
            )));

            return $content;
        } catch (Exception $e) {
            self::handleError('Cache Error', $e);
            return $callback();
        }
    }

    private static function handleError($error, $context = array())
    {
        // 记录错误
        self::log($error->getMessage(), 'error', $context);
        
        // 发送通知
        if (Helper::options()->plugin('AIBaTgrMX')->errorNotify) {
            self::sendErrorNotification($error);
        }
        
        // 显示错误
        if (Typecho_Widget::widget('Widget_User')->hasLogin()) {
            throw new Typecho_Plugin_Exception($error->getMessage());
        }
    }

    private static function getSystemPrompt($type, $options)
    {
        $defaultPrompts = [
            'summary' => "你是一位资深的文章摘要生成专家，擅长提炼文章精华。请严格遵循以下专业规范：

1. 输出规范：
   - 目标语言：{{LANGUAGE}}
   - 长度限制：{{MAX_LENGTH}}字
   - 输出格式：纯文本，无标记
   - 语言风格：保持原文基调

2. 内容提炼原则：
   - 信息密度：每句话都必须承载关键信息
   - 结构完整：确保包含文章的核心论点、支撑论据和结论
   - 逻辑连贯：保持文章的逻辑推进关系
   - 重点突出：优先保留原创性观点和创新性内容

3. 质量控制指标：
   - 信息覆盖率：核心观点覆盖率不低于90%
   - 表达准确性：不得歪曲原文意思
   - 语言简洁度：删除修饰性词语
   - 可读性指数：确保Flesch Reading Ease分数≥60

4. 处理步骤：
   a) 第一遍快速阅读：
      - 识别文章主题和中心思想
      - 标记关键论点和支撑证据
      - 注意特殊术语和专业概念
   
   b) 第二遍深度分析：
      - 构建文章逻辑框架
      - 提取核心论述内容
      - 保留关键数据和引用
   
   c) 第三遍优化处理：
      - 组织语言，确保流畅
      - 压缩冗余信息
      - 校验专业术语准确性

5. 严格禁止：
   - 添加原文未提及的观点
   - 使用不准确的类比或比喻
   - 改变原文的论述立场
   - 遗漏关键的限定条件

6. 特殊处理规则：
   - 技术文章：保留核心技术参数和方法论
   - 学术论文：突出研究方法和结论
   - 新闻报道：保留6W要素(Who,What,When,Where,Why,How)
   - 评论文章：突出观点和论据

请基于以上规范生成高质量摘要。",
            'tags' => "Generate up to {{MAX_TAGS}} tags in {{LANGUAGE}}, separated by commas.",
            'category' => "Select the most appropriate category from {{CATEGORIES}} for the given content.",
            'seo' => "Generate SEO meta description (max {{SEO_LENGTH}} chars) and keywords in {{LANGUAGE}}."
        ];

        $prompt = '';
        $targetLang = self::getTargetLanguage($options->currentContent ?? '');

        $langMap = [
            'zh' => '中文',
            'en' => 'English',
            'ja' => '日本語',
            'ko' => '한국어',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'es' => 'Español',
            'ru' => 'Русский'
        ];

        $language = $langMap[$targetLang] ?? 'English';

        switch ($type) {
            case 'summary':
                $prompt = $options->summaryPrompt ?: $defaultPrompts['summary'];
                $prompt = str_replace(['{{LANGUAGE}}', '{{MAX_LENGTH}}'],
                    [$language, $options->maxLength],
                    $prompt);
                break;

            case 'tags':
                $prompt = $options->tagsPrompt ?: $defaultPrompts['tags'];
                $prompt = str_replace(['{{LANGUAGE}}', '{{MAX_TAGS}}'],
                    [$language, $options->maxTags],
                    $prompt);
                break;

            case 'category':
                $prompt = $options->categoryPrompt ?: $defaultPrompts['category'];
                $prompt = str_replace(['{{LANGUAGE}}', '{{CATEGORIES}}'],
                    [$language, implode("\n", $options->categories ?? [])],
                    $prompt);
                break;

            case 'seo':
                $prompt = $options->seoPrompt ?: $defaultPrompts['seo'];
                $prompt = str_replace(['{{LANGUAGE}}', '{{SEO_LENGTH}}'],
                    [$language, $options->seoLength ?? 200],
                    $prompt);
                break;
        }

        return $prompt;
    }

    private static function getOrGenerateSEOInfo($archive)
    {
        return self::handleCache($archive->cid, 'seo', function() use ($archive) {
            try {
                $response = self::callApi($archive->text, 'seo');
                $seoData = json_decode($response, true);

                // 验证返回的数据格式是否正确
                if (!is_array($seoData) || !isset($seoData['description']) || !isset($seoData['keywords'])) {
                    throw new Exception('SEO数据格式无效');
                }

                return $seoData;

            } catch (Exception $e) {
                self::handleError('SEO生成', $e);
                // 返回一个格式正确的备用数组
                return array(
                    'description' => mb_substr($archive->excerpt ?? '', 0, 200),
                    'keywords' => is_array($archive->tags) ? implode(',', $archive->tags) : ($archive->tags ?? '')
                );
            }
        });
    }

    private static function optimizeFormat($content)
    {
        try {
            return self::callApi($content, 'format');
        } catch (Exception $e) {
            self::handleError('Format Optimization', $e);
            return $content;
        }
    }

    private static function generateOutline($content)
    {
        try {
            return self::callApi($content, 'outline');
        } catch (Exception $e) {
            self::handleError('Outline Generation', $e);
            return '';
        }
    }

    private static function getTargetLanguage($content)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');

        if ($options->language === 'auto') {
            return self::detectLanguage($content);
        }

        return $options->language;
    }

    private static function detectLanguage($content)
    {
        $sample = mb_substr($content, 0, 100);

        $zhCount = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $sample, $matches);
        $jaCount = preg_match_all('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $sample, $matches);
        $koCount = preg_match_all('/[\x{3130}-\x{318F}\x{AC00}-\x{D7AF}]/u', $sample, $matches);
        $ruCount = preg_match_all('/[\x{0400}-\x{04FF}]/u', $sample, $matches);

        $total = mb_strlen($sample);

        if ($zhCount / $total > 0.3) return 'zh';
        if ($jaCount / $total > 0.3) return 'ja';
        if ($koCount / $total > 0.3) return 'ko';
        if ($ruCount / $total > 0.3) return 'ru';

        return 'en';
    }

    public static function cleanGarbage()
    {
        if (!Typecho_Widget::widget('Widget_User')->hasLogin()) {
            header('HTTP/1.1 403 Forbidden');
            exit(json_encode(['success' => false, 'message' => '未登录']));
        }

        try {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
            $cleanOptions = $options->cleanOptions;
            $results = array();
            $totalCleaned = 0;

            if (in_array('cache', $cleanOptions)) {
                $cacheDir = __TYPECHO_ROOT_DIR__ . '/usr/cache/';
                $cleaned = self::cleanDirectory($cacheDir);
                $results[] = "清理缓存文件：{$cleaned}个";
                $totalCleaned += $cleaned;
            }

            if (in_array('temp', $cleanOptions)) {
                $tempDir = __TYPECHO_ROOT_DIR__ . '/usr/temp/';
                $cleaned = self::cleanDirectory($tempDir);
                $results[] = "清理临时文件：{$cleaned}个";
                $totalCleaned += $cleaned;
            }

            if (in_array('draft', $cleanOptions)) {
                $db = Typecho_Db::get();
                $drafts = $db->query($db->delete('table.contents')
                    ->where('type = ?', 'post_draft')
                    ->where('modified < ?', time() - 30 * 86400));
                $results[] = "清理草稿：{$drafts}篇";
                $totalCleaned += $drafts;
            }

            if (in_array('spam', $cleanOptions)) {
                $db = Typecho_Db::get();
                $spams = $db->query($db->delete('table.comments')
                    ->where('status = ?', 'spam')
                    ->where('created < ?', time() - 7 * 86400));
                $results[] = "清理垃圾评论：{$spams}条";
                $totalCleaned += $spams;
            }

            if (in_array('upload', $cleanOptions)) {
                $cleaned = self::cleanUnusedUploads();
                $results[] = "清理未引用文件：{$cleaned}个";
                $totalCleaned += $cleaned;
            }

            if (in_array('ai_cache', $cleanOptions)) {
                $db = Typecho_Db::get();
                $aiCaches = $db->query($db->delete('table.ai_content')
                    ->where('created < ?', time() - $options->cacheTime));
                $results[] = "清理AI缓存：{$aiCaches}条";
                $totalCleaned += $aiCaches;
            }

            Helper::options()->plugin('AIBaTgrMX')->lastCleanTime = time();

            $message = implode("\n", $results);
            exit(json_encode([
                'success' => true,
                'message' => $message,
                'total' => $totalCleaned
            ]));

        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }
    }

    private static function cleanDirectory($dir)
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $files = glob($dir . '*', GLOB_MARK);
        foreach ($files as $file) {
            if (is_dir($file)) {
                $count += self::cleanDirectory($file);
                @rmdir($file);
            } else {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private static function cleanUnusedUploads()
    {
        $db = Typecho_Db::get();
        $uploadDir = __TYPECHO_ROOT_DIR__ . '/usr/uploads/';
        $count = 0;

        $contents = $db->fetchAll($db->select('text, content')
            ->from('table.contents')
            ->where('type = ? OR type = ?', 'post', 'page'));

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        $usedFiles = array();
        foreach ($contents as $content) {
            preg_match_all('/\/usr\/uploads\/([^\s\'"<>)}\]]+)/', $content['text'] . $content['content'], $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $file) {
                    $usedFiles[$file] = true;
                }
            }
        }

        foreach ($files as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($uploadDir, '', $file->getPathname());
                if (!isset($usedFiles[$relativePath])) {
                    if (@unlink($file->getPathname())) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private static function warmupCache($content, $type)
    {
        try {
            $db = Typecho_Db::get();
            $cacheKey = md5($content . $type);

            $cached = $db->fetchRow($db->select()
                ->from('table.ai_content')
                ->where('type = ?', $type)
                ->where('cid = ?', $cacheKey));

            if (!$cached) {
                self::asyncApiCall($content, $type);
            }
        } catch (Exception $e) {
            self::handleError('Cache Warmup', $e);
        }
    }

    private static function asyncApiCall($content, $type)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $url = Typecho_Common::url('async-ai', $options->index);

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(array(
                'content' => $content,
                'type' => $type
            )),
            CURLOPT_NOSIGNAL => 1
        ));
        curl_exec($ch);
        curl_close($ch);
    }

    private static function securityCheck($content)
    {
        // 内容长度限制
        if (mb_strlen($content) > 50000) {
            throw new Exception(_t('内容长度超过限制'));
        }
        
        // XSS 防护
        $content = self::xssClean($content);

        // 敏感内容检查
        if (self::containsSensitiveContent($content)) {
            throw new Exception(_t('内容包含敏感信息'));
        }
        
        // CSRF 防护
        $security = Typecho_Widget::widget('Widget_Security');
        if (!$security->protect()) {
            throw new Exception(_t('安全检查失败'));
        }

        return $content;
    }

    private static function xssClean($data)
    {
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }

    private static function containsSensitiveContent($content)
    {
        $sensitiveWords = array(
            'password', 'pwd', 'secret', 'token',
            '密码', '密钥', '证书', '私钥'
        );
        
        foreach ($sensitiveWords as $word) {
            if (stripos($content, $word) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private static function trackApiUsage($type, $responseTime)
    {
        $db = Typecho_Db::get();
        $db->query($db->insert('table.ai_stats')->rows(array(
            'type' => $type,
            'response_time' => $responseTime,
            'created' => time()
        )));
    }

    private static function backupBeforeChange($content)
    {
        $backupDir = __TYPECHO_ROOT_DIR__ . '/usr/backup/';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupFile = $backupDir . date('Ymd_His') . '.json';
        file_put_contents($backupFile, json_encode(array(
            'content' => $content,
            'timestamp' => time()
        )));
    }

    private static function getCache($key)
    {
        $cacheFile = self::getCacheFile($key);
        if (!file_exists($cacheFile)) {
            return false;
        }
        
        $data = file_get_contents($cacheFile);
        $cache = json_decode($data, true);
        
        // 检查缓存是否过期
        if (time() - $cache['time'] > Helper::options()->plugin('AIBaTgrMX')->cacheExpire) {
            unlink($cacheFile);
            return false;
        }
        
        return $cache['data'];
    }

    private static function setCache($key, $data)
    {
        $cacheFile = self::getCacheFile($key);
        $cache = array(
            'time' => time(),
            'data' => $data
        );
        
        return file_put_contents($cacheFile, json_encode($cache));
    }

    private static function getCacheFile($key)
    {
        $dir = __TYPECHO_ROOT_DIR__ . '/usr/cache/ai_assistant';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/' . md5($key) . '.cache';
    }

    private static function log($message, $level = 'info', $context = array())
    {
        static $logger = null;
        
        if (null === $logger) {
            $logger = new Typecho_Logger(__TYPECHO_ROOT_DIR__ . '/usr/logs/ai_assistant');
        }
        
        $logger->log($level, $message, $context);
    }

    private static function batchInsert($table, $rows)
    {
        if (empty($rows)) {
            return;
        }
        
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        
        // 构建批量插入SQL
        $fields = array_keys(reset($rows));
        $values = array();
        $placeholders = array();
        
        foreach ($rows as $row) {
            $rowPlaceholders = array();
            foreach ($row as $value) {
                $values[] = $value;
                $rowPlaceholders[] = '?';
            }
            $placeholders[] = '(' . implode(',', $rowPlaceholders) . ')';
        }
        
        $sql = "INSERT INTO `{$prefix}{$table}` (" . 
               implode(',', array_map(function($field) { return "`$field`"; }, $fields)) . 
               ") VALUES " . implode(',', $placeholders);
        
        $db->query($sql, $values);
    }

    private static function encryptApiKey($key)
    {
        return Typecho_Cookie::encrypt($key, Helper::options()->secret);
    }

    private static function decryptApiKey($encrypted)
    {
        return Typecho_Cookie::decrypt($encrypted, Helper::options()->secret);
    }

    private static function preprocessContent($content, $type)
    {
        // 清理HTML标签
        $content = strip_tags($content);
        
        // 移除多余空白
        $content = preg_replace('/\s+/', ' ', trim($content));
        
        // 根据不同类型进行特定处理
        switch ($type) {
            case 'summary':
                // 提取关键段落
                $content = self::extractMainContent($content);
                break;
            
            case 'tags':
                // 移除停用词
                $content = self::removeStopWords($content);
                break;
            
            case 'seo':
                // 提取关键信息
                $content = self::extractKeyInfo($content);
                break;
        }
        
        return $content;
    }

    private static function postprocessContent($content, $type)
    {
        switch ($type) {
            case 'summary':
                // 确保摘要完整性
                $content = self::ensureCompleteSentences($content);
                // 检查关键信息是否保留
                $content = self::validateKeyInfo($content);
                break;
            
            case 'tags':
                // 过滤无效标签
                $content = self::filterTags($content);
                // 标准化标签格式
                $content = self::normalizeTags($content);
                break;
            
            case 'seo':
                // 优化SEO描述
                $content = self::optimizeSeoDescription($content);
                break;
        }
        
        return $content;
    }

    private static function validateContent($content, $type, $original)
    {
        $score = 0;
        $threshold = 0.7; // 质量阈值
        
        // 基础检查
        if (empty($content)) {
            return false;
        }
        
        switch ($type) {
            case 'summary':
                // 检查关键词覆盖率
                $score += self::checkKeywordsCoverage($content, $original);
                // 检查语义相似度
                $score += self::checkSemanticSimilarity($content, $original);
                // 检查摘要完整性
                $score += self::checkSummaryCompleteness($content);
                break;
            
            case 'tags':
                // 检查标签相关性
                $score += self::checkTagsRelevance($content, $original);
                // 检查标签多样性
                $score += self::checkTagsDiversity($content);
                // 检查标签流行度
                $score += self::checkTagsPopularity($content);
                break;
            
            case 'seo':
                // 检查SEO优化度
                $score += self::checkSeoOptimization($content);
                // 检查关键词密度
                $score += self::checkKeywordDensity($content);
                break;
        }
        
        return ($score / 3) >= $threshold;
    }

    private static function checkKeywordsCoverage($content, $original)
    {
        $keywords = self::extractKeywords($original);
        $count = 0;
        
        foreach ($keywords as $keyword) {
            if (stripos($content, $keyword) !== false) {
                $count++;
            }
        }
        
        return $count / count($keywords);
    }

    private static function checkSemanticSimilarity($content, $original)
    {
        // 使用词向量计算语义相似度
        $contentVector = self::textToVector($content);
        $originalVector = self::textToVector($original);
        
        return self::cosineSimilarity($contentVector, $originalVector);
    }

    private static function generateWithRetry($prompt, $type, $maxRetries = 3)
    {
        $attempts = 0;
        $bestResult = null;
        $bestScore = 0;
        
        while ($attempts < $maxRetries) {
            try {
                // 生成内容
                $result = self::callApi($prompt, $type);
                
                // 后处理
                $processed = self::postprocessContent($result, $type);
                
                // 质量评分
                $score = self::evaluateQuality($processed, $type);
                
                // 更新最佳结果
                if ($score > $bestScore) {
                    $bestResult = $processed;
                    $bestScore = $score;
                    
                    // 如果质量足够好，提前返回
                    if ($score >= 0.8) {
                        break;
                    }
                }
                
                $attempts++;
                
                // 调整提示词
                $prompt = self::adjustPrompt($prompt, $result, $score);
                
            } catch (Exception $e) {
                self::log("Generation attempt {$attempts} failed: " . $e->getMessage(), 'warning');
                $attempts++;
            }
        }
        
        if ($bestResult === null) {
            throw new Exception('Failed to generate satisfactory content');
        }
        
        return $bestResult;
    }

    private static function evaluateQuality($content, $type)
    {
        $score = 0;
        
        // 基础质量检查
        $score += self::checkBasicQuality($content);
        
        // 特定类型检查
        switch ($type) {
            case 'summary':
                $score += self::evaluateSummaryQuality($content);
                break;
            case 'tags':
                $score += self::evaluateTagsQuality($content);
                break;
            case 'seo':
                $score += self::evaluateSeoQuality($content);
                break;
        }
        
        return $score / 2; // 归一化到0-1范围
    }

    private static function adjustPrompt($prompt, $result, $score)
    {
        // 根据生成结果和质量分数调整提示词
        if ($score < 0.3) {
            // 质量太差，增加更多约束
            $prompt .= "\n请确保输出更加准确和相关。";
        } elseif ($score < 0.6) {
            // 质量一般，微调提示
            $prompt .= "\n请保持现有质量，但增加更多细节。";
        }
        
        return $prompt;
    }

    private static function getContentContext($cid)
    {
        $db = Typecho_Db::get();
        
        // 获取文章信息
        $post = $db->fetchRow($db->select()
            ->from('table.contents')
            ->where('cid = ?', $cid));
        
        if (!$post) {
            return array();
        }
        
        // 获取分类信息
        $categories = $db->fetchAll($db->select('name')
            ->from('table.metas')
            ->join('table.relationships', 'table.metas.mid = table.relationships.mid')
            ->where('table.relationships.cid = ?', $cid)
            ->where('table.metas.type = ?', 'category'));
        
        // 获取标签信息
        $tags = $db->fetchAll($db->select('name')
            ->from('table.metas')
            ->join('table.relationships', 'table.metas.mid = table.relationships.mid')
            ->where('table.relationships.cid = ?', $cid)
            ->where('table.metas.type = ?', 'tag'));
        
        return array(
            'title' => $post['title'],
            'categories' => array_column($categories, 'name'),
            'tags' => array_column($tags, 'name'),
            'created' => $post['created'],
            'modified' => $post['modified']
        );
    }

    private static function getPromptTemplate($type)
    {
        $templates = array(
            'summary' => array(
                'system' => "你是一位资深的文章摘要生成专家，擅长提炼文章精华。请严格遵循以下专业规范：

1. 输出规范：
   - 目标语言：{{LANGUAGE}}
   - 长度限制：{{MAX_LENGTH}}字
   - 输出格式：纯文本，无标记
   - 语言风格：保持原文基调

2. 内容提炼原则：
   - 信息密度：每句话都必须承载关键信息
   - 结构完整：确保包含文章的核心论点、支撑论据和结论
   - 逻辑连贯：保持文章的逻辑推进关系
   - 重点突出：优先保留原创性观点和创新性内容

3. 质量控制指标：
   - 信息覆盖率：核心观点覆盖率不低于90%
   - 表达准确性：不得歪曲原文意思
   - 语言简洁度：删除修饰性词语
   - 可读性指数：确保Flesch Reading Ease分数≥60

4. 处理步骤：
   a) 第一遍快速阅读：
      - 识别文章主题和中心思想
      - 标记关键论点和支撑证据
      - 注意特殊术语和专业概念
   
   b) 第二遍深度分析：
      - 构建文章逻辑框架
      - 提取核心论述内容
      - 保留关键数据和引用
   
   c) 第三遍优化处理：
      - 组织语言，确保流畅
      - 压缩冗余信息
      - 校验专业术语准确性

5. 严格禁止：
   - 添加原文未提及的观点
   - 使用不准确的类比或比喻
   - 改变原文的论述立场
   - 遗漏关键的限定条件

6. 特殊处理规则：
   - 技术文章：保留核心技术参数和方法论
   - 学术论文：突出研究方法和结论
   - 新闻报道：保留6W要素(Who,What,When,Where,Why,How)
   - 评论文章：突出观点和论据

请基于以上规范生成高质量摘要。",
                'user' => "请为以下文章生成一个专业的摘要：\n\n{{CONTENT}}\n\n要求：确保摘要完整、准确、简洁。"
            ),
            
            'tags' => array(
                'system' => "你是一位专业的语义标签系统专家，擅长提取文本关键概念。请严格遵循以下标准：

1. 输出规范：
   - 目标语言：{{LANGUAGE}}
   - 数量上限：{{MAX_TAGS}}个
   - 格式要求：小写字母，逗号分隔
   - 标签长度：2-20个字符

2. 标签质量标准：
   - 相关性：与文章主题直接相关
   - 专业性：优先使用行业标准术语
   - 层次性：包含不同粒度的概念
   - 独特性：避免过于通用的词汇

3. 标签选择优先级：
   第一级：核心技术术语和专业概念
   第二级：主要研究方向和应用领域
   第三级：相关技术栈和工具
   第四级：一般性描述词

4. 标签生成流程：
   a) 文本分析：
      - 分词和词性标注
      - 术语识别和抽取
      - 主题聚类分析
   
   b) 标签筛选：
      - 计算词频和权重
      - 评估专业性和独特性
      - 检查覆盖度和完整性
   
   c) 标签优化：
      - 标准化处理
      - 去重和合并
      - 排序和筛选

5. 质量控制：
   - 准确性：标签必须在文中有明确对应
   - 完整性：覆盖文章主要主题
   - 专业性：符合领域术语规范
   - 实用性：具有检索和分类价值

6. 特殊处理规则：
   - 技术文章：突出技术栈和框架
   - 学术论文：强调研究方向和方法
   - 产品介绍：关注功能和特性
   - 教程文档：标注难度和应用场景

请基于以上规范生成高质量标签。",
                'user' => "请为以下文章生成专业的标签集合：\n\n{{CONTENT}}\n\n注意：确保标签专业、准确、有价值。"
            ),
            
            'seo' => array(
                'system' => "你是一位资深的SEO优化专家，精通搜索引擎算法和用户行为分析。请严格遵循以下规范：

1. 输出要求：
   - 目标语言：{{LANGUAGE}}
   - 描述长度：{{SEO_LENGTH}}字以内
   - 输出格式：严格的JSON格式
   - 必需字段：description, keywords

2. Meta Description优化准则：
   - 信息架构：
     * 核心价值主张（前20%）
     * 关键特性/优势（中间60%）
     * 行动召唤（后20%）
   
   - 写作要求：
     * 包含主要关键词（密度2-3%）
     * 突出独特价值主张
     * 使用行动导向语言
     * 保持自然流畅
   
   - 技术规范：
     * 字符长度：50-160字
     * 关键词位置：前60个字符
     * 标点符号：合理使用，避免过度
     * 特殊字符：仅使用标准字符

3. Keywords优化准则：
   - 关键词层次：
     * 主要关键词（2-3个）
     * 相关关键词（3-5个）
     * 长尾关键词（2-3个）
   
   - 选择标准：
     * 搜索意图匹配度
     * 竞争度评估
     * 转化潜力
     * 流量价值
   
   - 组合规则：
     * 优先级排序
     * 语义相关性
     * 搜索量平衡
     * 竞争度均衡

4. 优化策略：
   - 搜索意图对齐
   - 竞争度分析
   - 用户体验考虑
   - 转化率优化

5. 质量检查：
   - 相关性评分
   - 可读性指数
   - 独特性检查
   - 竞争力分析

请基于以上规范生成专业的SEO优化方案。",
                'user' => "请为以下文章生成SEO优化方案：\n\n{{CONTENT}}\n\n注意：确保优化方案专业、有效、符合最新SEO标准。"
            ),
            
            'category' => array(
                'system' => "你是一位专业的文章分类专家，精通内容分析和分类体系。请严格遵循以下规范：

1. 分类判定准则：
   - 主题相关性（权重40%）：
     * 核心主题匹配
     * 内容领域对应
     * 专业方向一致
   
   - 内容类型（权重30%）：
     * 文章形式
     * 写作风格
     * 内容深度
   
   - 目标受众（权重20%）：
     * 阅读群体
     * 专业水平
     * 兴趣偏好
   
   - 实用价值（权重10%）：
     * 应用场景
     * 参考价值
     * 时效性

2. 分析流程：
   a) 内容解析：
      - 提取主题关键词
      - 识别专业术语
      - 判断内容类型
   
   b) 匹配评估：
      - 计算相似度得分
      - 评估分类适配度
      - 验证分类准确性
   
   c) 最终决策：
      - 综合各项指标
      - 选择最佳匹配
      - 确保分类合理

3. 现有分类列表：
{{CATEGORIES}}

4. 输出要求：
   - 格式：单个分类名称
   - 准确性：必须完全匹配列表
   - 一致性：区分大小写
   - 唯一性：只返回最佳匹配

请基于以上规范进行专业的分类推荐。",
                'user' => "请为以下文章推荐最合适的分类：\n\n{{CONTENT}}\n\n注意：必须从现有分类中选择，确保分类准确、合理。"
            )
        );
        
        return isset($templates[$type]) ? $templates[$type] : array();
    }

    private static function processPromptVariables($prompt, $variables)
    {
        $replacements = array(
            '{{LANGUAGE}}' => $variables['language'] ?? '中文',
            '{{MAX_LENGTH}}' => $variables['maxLength'] ?? '200',
            '{{MAX_TAGS}}' => $variables['maxTags'] ?? '5',
            '{{SEO_LENGTH}}' => $variables['seoLength'] ?? '150',
            '{{CATEGORIES}}' => $variables['categories'] ?? '',
            '{{CONTENT}}' => $variables['content'] ?? ''
        );
        
        // 处理自定义变量
        if (isset($variables['custom']) && is_array($variables['custom'])) {
            foreach ($variables['custom'] as $key => $value) {
                $replacements['{{'.$key.'}}'] = $value;
            }
        }
        
        return str_replace(array_keys($replacements), array_values($replacements), $prompt);
    }

    private static function optimizePrompt($prompt, $type, $context = array())
    {
        // 根据内容长度调整提示词
        if (mb_strlen($context['content']) > 5000) {
            $prompt .= "\n请注意：这是一篇较长的文章，请确保抓住主要内容。";
        }
        
        // 根据内容类型调整提示词
        if (isset($context['contentType'])) {
            switch ($context['contentType']) {
                case 'technical':
                    $prompt .= "\n这是一篇技术文章，请保持专业术语的准确性。";
                    break;
                case 'news':
                    $prompt .= "\n这是一篇新闻文章，请保持客观中立的语气。";
                    break;
                case 'blog':
                    $prompt .= "\n这是一篇博客文章，可以保持个人风格。";
                    break;
            }
        }
        
        // 根据历史生成结果调整
        if (isset($context['history']) && !empty($context['history'])) {
            $prompt .= "\n参考以下历史生成结果，保持风格一致性：\n" . 
                      implode("\n", array_slice($context['history'], -3));
        }
        
        return $prompt;
    }

    private static function generateContent($content, $type, $options)
    {
        // 获取提示词模板
        $template = self::getPromptTemplate($type);
        if (empty($template)) {
            throw new Exception('未找到对应类型的提示词模板');
        }
        
        // 准备变量
        $variables = array(
            'language' => $options->language,
            'maxLength' => $options->maxLength,
            'maxTags' => $options->maxTags,
            'seoLength' => $options->seoLength,
            'categories' => implode("\n", $options->categories ?? array()),
            'content' => $content
        );
        
        // 处理系统提示词
        $systemPrompt = self::processPromptVariables($template['system'], $variables);
        
        // 处理用户提示词
        $userPrompt = self::processPromptVariables($template['user'], $variables);
        
        // 获取上下文信息
        $context = self::getContentContext($options->cid);
        
        // 优化提示词
        $systemPrompt = self::optimizePrompt($systemPrompt, $type, $context);
        $userPrompt = self::optimizePrompt($userPrompt, $type, $context);
        
        // 生成内容
        return self::generateWithRetry(array(
            'system' => $systemPrompt,
            'user' => $userPrompt
        ), $type);
    }

    private static function checkPluginCompatibility()
    {
        $plugins = Helper::options()->plugins;
        $incompatiblePlugins = array();
        
        // 已知冲突的插件列表
        $knownConflicts = array(
            'AutoSummary' => '文章摘要功能冲突',
            'TagGenerator' => '标签生成功能冲突',
            'SEOOptimizer' => 'SEO优化功能冲突'
        );
        
        // 检查已激活的插件
        foreach ($plugins['activated'] as $plugin) {
            if (isset($knownConflicts[$plugin])) {
                $incompatiblePlugins[$plugin] = $knownConflicts[$plugin];
            }
            
            // 检查钩子冲突
            if (self::hasHookConflict($plugin)) {
                $incompatiblePlugins[$plugin] = '钩子函数冲突';
            }
        }
        
        if (!empty($incompatiblePlugins)) {
            $messages = array();
            foreach ($incompatiblePlugins as $plugin => $reason) {
                $messages[] = "- {$plugin}: {$reason}";
            }
            throw new Typecho_Plugin_Exception(_t('检测到以下插件可能存在兼容性问题：\n') . implode("\n", $messages));
        }
    }

    private static function hasHookConflict($plugin)
    {
        // 获取插件类名
        $class = $plugin . '_Plugin';
        if (!class_exists($class)) {
            return false;
        }
        
        // 检查是否使用相同的钩子
        $ourHooks = array(
            'Widget_Abstract_Contents:excerptEx',
            'Widget_Contents_Post_Edit:write',
            'Widget_Archive:header'
        );
        
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            foreach ($ourHooks as $hook) {
                list($class, $name) = explode(':', $hook);
                if (strpos($method->getName(), $name) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }

    private static function checkVersionCompatibility()
    {
        // 检查PHP版本
        if (version_compare(PHP_VERSION, '7.2.0', '<')) {
            throw new Typecho_Plugin_Exception(_t('需要PHP 7.2.0或更高版本'));
        }
        
        // 检查Typecho版本
        if (version_compare(Typecho_Common::VERSION, '1.2.0', '<')) {
            throw new Typecho_Plugin_Exception(_t('需要Typecho 1.2.0或更高版本'));
        }
        
        // 检查必要的PHP扩展
        $requiredExtensions = array(
            'curl' => '用于API调用',
            'json' => '用于数据处理',
            'mbstring' => '用于字符串处理',
            'pdo' => '用于数据库操作'
        );
        
        $missingExtensions = array();
        foreach ($requiredExtensions as $ext => $usage) {
            if (!extension_loaded($ext)) {
                $missingExtensions[] = "{$ext}({$usage})";
            }
        }
        
        if (!empty($missingExtensions)) {
            throw new Typecho_Plugin_Exception(_t('缺少必要的PHP扩展：%s', implode(', ', $missingExtensions)));
        }
    }

    private static function ensureCompatibility($content, $context)
    {
        // 处理特殊字符
        $content = self::fixCharacterEncoding($content);
        
        // 处理HTML标签
        $content = self::fixHtmlTags($content);
        
        // 处理主题特定标记
        $content = self::fixThemeMarkers($content);
        
        // 处理插件冲突
        $content = self::resolvePluginConflicts($content, $context);
        
        return $content;
    }

    private static function fixCharacterEncoding($content)
    {
        // 检测编码
        $encoding = mb_detect_encoding($content, array('UTF-8', 'GBK', 'GB2312'));
        
        // 统一转换为UTF-8
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        
        // 修复特殊字符
        $content = htmlspecialchars_decode($content, ENT_QUOTES);
        
        return $content;
    }

    private static function fixHtmlTags($content)
    {
        // 统一处理HTML标签
        $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
        $content = strip_tags($content, '<p><br><a><strong><em>');
        
        return $content;
    }

    private static function addSeoConfig(Typecho_Widget_Helper_Form $form)
    {
        // SEO基础设置
        $seoBasic = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'seoFeatures',
            array(
                'meta' => _t('Meta信息优化'),
                'schema' => _t('结构化数据(Schema.org)'),
                'readability' => _t('可读性分析'),
                'heading' => _t('标题层级优化'),
                'link' => _t('链接优化建议'),
                'image' => _t('图片SEO优化')
            ),
            array('meta', 'schema'),
            _t('SEO功能'),
            _t('选择需要启用的SEO优化功能')
        );
        $form->addInput($seoBasic);
        
        // Schema.org类型设置
        $schemaTypes = new Typecho_Widget_Helper_Form_Element_Select(
            'schemaType',
            array(
                'Article' => _t('文章(Article)'),
                'BlogPosting' => _t('博客文章(BlogPosting)'),
                'NewsArticle' => _t('新闻文章(NewsArticle)'),
                'TechArticle' => _t('技术文章(TechArticle)')
            ),
            'BlogPosting',
            _t('默认Schema类型'),
            _t('选择文章的默认结构化数据类型')
        );
        $form->addInput($schemaTypes);
        
        // 自定义Meta标签
        $customMeta = new Typecho_Widget_Helper_Form_Element_Textarea(
            'customMeta',
            NULL,
            '',
            _t('自定义Meta标签'),
            _t('每行一个，格式：name=content 或 property=content')
        );
        $form->addInput($customMeta);
    }

    private static function generateSchemaData($post, $type = 'BlogPosting')
    {
        $options = Helper::options();
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => $type,
            'headline' => $post->title,
            'description' => self::generateMetaDescription($post->text),
            'author' => array(
                '@type' => 'Person',
                'name' => $post->author->screenName
            ),
            'datePublished' => date(DATE_ISO8601, $post->created),
            'dateModified' => date(DATE_ISO8601, $post->modified),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => $options->title,
                'logo' => array(
                    '@type' => 'ImageObject',
                    'url' => $options->siteUrl . 'logo.png'
                )
            ),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id' => $post->permalink
            )
        );
        
        // 添加文章图片
        if ($post->fields->thumbnail) {
            $schema['image'] = array(
                '@type' => 'ImageObject',
                'url' => $post->fields->thumbnail,
                'width' => 1200,
                'height' => 630
            );
        }
        
        // 添加文章分类
        if ($post->categories) {
            $schema['keywords'] = implode(',', array_column($post->categories, 'name'));
        }
        
        // 添加特定类型的额外属性
        switch ($type) {
            case 'TechArticle':
                $schema['proficiencyLevel'] = 'Expert';
                $schema['dependencies'] = self::extractTechDependencies($post->text);
                break;
                
            case 'NewsArticle':
                $schema['dateline'] = date('Y-m-d', $post->created);
                $schema['printSection'] = $post->categories[0]->name;
                break;
        }
        
        return $schema;
    }

    private static function analyzeReadability($content)
    {
        $analysis = array(
            'score' => 0,
            'issues' => array(),
            'suggestions' => array()
        );
        
        // 分析段落长度
        $paragraphs = explode("\n\n", $content);
        foreach ($paragraphs as $index => $paragraph) {
            $wordCount = str_word_count(strip_tags($paragraph));
            if ($wordCount > 150) {
                $analysis['issues'][] = array(
                    'type' => 'long_paragraph',
                    'position' => $index,
                    'message' => _t('段落过长，建议拆分')
                );
            }
        }
        
        // 分析句子长度
        $sentences = preg_split('/[.!?。！？]+/u', $content);
        foreach ($sentences as $index => $sentence) {
            $wordCount = str_word_count(strip_tags($sentence));
            if ($wordCount > 30) {
                $analysis['issues'][] = array(
                    'type' => 'long_sentence',
                    'position' => $index,
                    'message' => _t('句子过长，建议简化')
                );
            }
        }
        
        // 分析标题层级
        $headings = self::analyzeHeadings($content);
        if (!empty($headings['issues'])) {
            $analysis['issues'] = array_merge($analysis['issues'], $headings['issues']);
        }
        
        // 计算可读性得分
        $analysis['score'] = self::calculateReadabilityScore($content);
        
        // 生成改进建议
        $analysis['suggestions'] = self::generateReadabilitySuggestions($analysis);
        
        return $analysis;
    }

    private static function calculateReadabilityScore($content)
    {
        // 计算Flesch-Kincaid可读性得分
        $words = str_word_count(strip_tags($content));
        $sentences = count(preg_split('/[.!?。！？]+/u', $content));
        $syllables = self::countSyllables($content);
        
        if ($sentences == 0) return 0;
        
        return 206.835 - 1.015 * ($words / $sentences) - 84.6 * ($syllables / $words);
    }

    private static function generateReadabilitySuggestions($analysis)
    {
        $suggestions = array();
        
        // 根据分析结果生成建议
        if ($analysis['score'] < 60) {
            $suggestions[] = _t('文章整体可读性较低，建议：');
            $suggestions[] = _t('- 使用更简单的词汇');
            $suggestions[] = _t('- 缩短句子长度');
            $suggestions[] = _t('- 增加段落间的过渡');
        }
        
        // 处理具体问题
        $issueCount = array_count_values(array_column($analysis['issues'], 'type'));
        
        if (isset($issueCount['long_paragraph']) && $issueCount['long_paragraph'] > 2) {
            $suggestions[] = _t('文章包含多个过长段落，建议将其拆分为更短的段落');
        }
        
        if (isset($issueCount['long_sentence']) && $issueCount['long_sentence'] > 3) {
            $suggestions[] = _t('文章包含多个复杂句子，建议简化表达');
        }
        
        return $suggestions;
    }

    private static function optimizeLinks($content)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $links = $dom->getElementsByTagName('a');
        $issues = array();
        
        foreach ($links as $link) {
            // 检查链接文本
            $text = $link->textContent;
            if (strlen($text) < 3 || in_array(strtolower($text), array('点击', 'click', 'here'))) {
                $issues[] = array(
                    'type' => 'weak_anchor',
                    'text' => $text,
                    'suggestion' => _t('使用更具描述性的链接文本')
                );
            }
            
            // 检查外部链接
            $href = $link->getAttribute('href');
            if (strpos($href, Helper::options()->siteUrl) === false && !$link->hasAttribute('rel')) {
                $link->setAttribute('rel', 'noopener noreferrer');
            }
            
            // 检查死链
            if (!self::isLinkAlive($href)) {
                $issues[] = array(
                    'type' => 'dead_link',
                    'url' => $href,
                    'suggestion' => _t('链接可能已失效，请检查')
                );
            }
        }
        
        return array(
            'content' => $dom->saveHTML(),
            'issues' => $issues
        );
    }

    private static function optimizeImages($content)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $images = $dom->getElementsByTagName('img');
        $issues = array();
        
        foreach ($images as $img) {
            // 检查alt属性
            if (!$img->hasAttribute('alt') || empty($img->getAttribute('alt'))) {
                $issues[] = array(
                    'type' => 'missing_alt',
                    'src' => $img->getAttribute('src'),
                    'suggestion' => _t('添加描述性的alt文本')
                );
            }
            
            // 检查图片尺寸
            if (!$img->hasAttribute('width') || !$img->hasAttribute('height')) {
                list($width, $height) = getimagesize($img->getAttribute('src'));
                $img->setAttribute('width', $width);
                $img->setAttribute('height', $height);
            }
            
            // 添加loading属性
            if (!$img->hasAttribute('loading')) {
                $img->setAttribute('loading', 'lazy');
            }
        }
        
        return array(
            'content' => $dom->saveHTML(),
            'issues' => $issues
        );
    }

    private static function getSeoPrompt($content, $type)
    {
        $prompts = array(
            'meta' => "你是一位专业的SEO专家，请为以下内容生成Meta信息：

要求：
1. Description要求：
   - 长度：50-160字符
   - 包含主要关键词
   - 突出核心价值
   - 吸引用户点击
   - 自然流畅

2. Keywords要求：
   - 主要关键词(2-3个)
   - 相关关键词(3-5个)
   - 长尾关键词(2-3个)
   - 按重要性排序

输出格式：
{
  \"description\": \"优化后的描述\",
  \"keywords\": \"关键词1,关键词2,关键词3\"
}

内容如下：
{$content}",

            'schema' => "你是一位结构化数据专家，请为以下内容生成Schema.org标记：

要求：
1. 基本信息完整性
2. 属性值准确性
3. 遵循Schema.org规范
4. 优化搜索展示效果

输出格式：
{
  \"@context\": \"https://schema.org\",
  \"@type\": \"Article\",
  ...其他属性
}

内容如下：
{$content}",

            'readability' => "你是一位内容可读性优化专家，请分析以下内容：

分析维度：
1. 段落结构
2. 句子长度
3. 标题层级
4. 关键词密度
5. 文本通顺度

输出格式：
{
  \"score\": 0-100的可读性得分,
  \"issues\": [
    {
      \"type\": \"问题类型\",
      \"position\": \"问题位置\",
      \"suggestion\": \"改进建议\"
    }
  ],
  \"suggestions\": [
    \"总体建议1\",
    \"总体建议2\"
  ]
}

内容如下：
{$content}"
        );

        return isset($prompts[$type]) ? $prompts[$type] : '';
    }

    private static function optimizeTextProcessing($content, $type)
    {
        // 文本预处理
        $content = self::preprocessContent($content, $type);
        
        // 根据类型确定分段策略
        $strategy = self::getSegmentationStrategy($type);
        
        // 智能分段
        $segments = self::smartSegmentation($content, $strategy);
        
        // 批量处理
        $results = array();
        foreach ($segments as $segment) {
            // 检查缓存
            $cacheKey = md5($segment . $type);
            $cached = self::getCache($cacheKey);
            
            if ($cached !== false) {
                $results[] = $cached;
                continue;
            }
            
            // 处理单个段落
            $result = self::processSegment($segment, $type);
            
            // 缓存结果
            self::setCache($cacheKey, $result);
            
            $results[] = $result;
        }
        
        // 合并结果
        return self::mergeResults($results, $type);
    }

    private static function getSegmentationStrategy($type)
    {
        return array(
            'summary' => array(
                'maxLength' => 2000,
                'minLength' => 500,
                'overlap' => 100,
                'method' => 'semantic'
            ),
            'tags' => array(
                'maxLength' => 4000,
                'minLength' => 1000,
                'overlap' => 200,
                'method' => 'hybrid'
            ),
            'seo' => array(
                'maxLength' => 3000,
                'minLength' => 800,
                'overlap' => 150,
                'method' => 'smart'
            )
        )[$type] ?? array(
            'maxLength' => 2000,
            'minLength' => 500,
            'overlap' => 100,
            'method' => 'default'
        );
    }

    private static function smartSegmentation($content, $strategy)
    {
        $segments = array();
        $content = trim($content);
        
        // 1. 首先尝试按语义单元分割
        if ($strategy['method'] === 'semantic') {
            $segments = self::semanticSegmentation($content, $strategy);
        }
        // 2. 混合分割策略
        elseif ($strategy['method'] === 'hybrid') {
            $segments = self::hybridSegmentation($content, $strategy);
        }
        // 3. 智能分割
        elseif ($strategy['method'] === 'smart') {
            $segments = self::smartSplit($content, $strategy);
        }
        // 4. 默认分割
        else {
            $segments = self::defaultSegmentation($content, $strategy);
        }
        
        // 优化分段结果
        $segments = array_map('trim', $segments);
        $segments = array_filter($segments, function($seg) use ($strategy) {
            return mb_strlen($seg) >= $strategy['minLength'];
        });
        
        return array_values($segments);
    }

    private static function semanticSegmentation($content, $strategy)
    {
        $segments = array();
        
        // 按段落分割
        $paragraphs = preg_split('/\n\s*\n/', $content);
        $currentSegment = '';
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) continue;
            
            // 检查当前段落是否完整语义单元
            if (self::isCompleteSemantic($paragraph)) {
                // 如果当前累积内容加上这个段落超过最大长度
                if (mb_strlen($currentSegment . $paragraph) > $strategy['maxLength']) {
                    if (!empty($currentSegment)) {
                        $segments[] = $currentSegment;
                    }
                    $currentSegment = $paragraph;
                } else {
                    $currentSegment .= (empty($currentSegment) ? '' : "\n\n") . $paragraph;
                }
            } else {
                $currentSegment .= (empty($currentSegment) ? '' : "\n\n") . $paragraph;
            }
            
            // 检查是否需要强制分段
            if (mb_strlen($currentSegment) >= $strategy['maxLength']) {
                $segments[] = $currentSegment;
                $currentSegment = '';
            }
        }
        
        if (!empty($currentSegment)) {
            $segments[] = $currentSegment;
        }
        
        return $segments;
    }

    private static function isCompleteSemantic($text)
    {
        // 检查是否以完整句子结尾
        return (bool) preg_match('/[.!?。！？]\s*$/', $text);
    }

    private static function addTaskQueue()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{prefix}ai_tasks` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `type` varchar(20) NOT NULL,
            `content_id` int(11) NOT NULL,
            `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
            `priority` tinyint(4) NOT NULL DEFAULT '0',
            `created` int(10) unsigned NOT NULL,
            `started` int(10) unsigned DEFAULT NULL,
            `completed` int(10) unsigned DEFAULT NULL,
            `result` text,
            `error` text,
            `retries` tinyint(4) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `status_priority` (`status`,`priority`),
            KEY `content_id` (`content_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $db = Typecho_Db::get();
        $db->query(str_replace('{prefix}', $db->getPrefix(), $sql));
    }

    private static function queueTask($type, $contentId, $content, $priority = 0)
    {
        $db = Typecho_Db::get();
        
        $task = array(
            'type' => $type,
            'content_id' => $contentId,
            'status' => 'pending',
            'priority' => $priority,
            'created' => time()
        );
        
        try {
            $db->query($db->insert('table.ai_tasks')->rows($task));
            return $db->lastInsertId();
        } catch (Exception $e) {
            self::log('Failed to queue task: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    private static function processTaskQueue()
    {
        $db = Typecho_Db::get();
        $options = Helper::options()->plugin('AIBaTgrMX');
        
        // 获取系统负载
        $load = sys_getloadavg();
        if ($load[0] > $options->maxLoad) {
            self::log('System load too high: ' . $load[0], 'warning');
            return;
        }
        
        // 获取待处理任务
        $tasks = $db->fetchAll($db->select()
            ->from('table.ai_tasks')
            ->where('status = ?', 'pending')
            ->where('retries < ?', 3)
            ->order('priority DESC, created ASC')
            ->limit($options->batchSize));
        
        // 使用事务处理批量任务
        try {
            $db->beginTransaction();
            
            foreach ($tasks as $task) {
                // 锁定任务
                $locked = $db->query($db->update('table.ai_tasks')
                    ->rows(array(
                        'status' => 'processing',
                        'started' => time(),
                        'locked_by' => getmypid()
                    ))
                    ->where('id = ?', $task['id'])
                    ->where('status = ?', 'pending'));
                
                if (!$locked) continue;
                
                try {
                    // 处理任务
                    $result = self::processTask($task);
                    
                    // 更新任务状态
                    $db->query($db->update('table.ai_tasks')
                        ->rows(array(
                            'status' => 'completed',
                            'completed' => time(),
                            'result' => json_encode($result),
                            'locked_by' => null
                        ))
                        ->where('id = ?', $task['id']));
                        
                } catch (Exception $e) {
                    // 记录错误并更新重试次数
                    $db->query($db->update('table.ai_tasks')
                        ->rows(array(
                            'status' => 'failed',
                            'error' => $e->getMessage(),
                            'retries' => new Typecho_Db_Expression('retries + 1'),
                            'locked_by' => null
                        ))
                        ->where('id = ?', $task['id']));
                    
                    self::log('Task processing failed: ' . $e->getMessage(), 'error');
                }
                
                // 检查是否需要暂停（避免过度消耗资源）
                if (self::shouldPause()) {
                    break;
                }
            }
            
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollBack();
            self::log('Task queue processing failed: ' . $e->getMessage(), 'error');
        }
    }

    private static function shouldPause()
    {
        static $lastCheck = 0;
        static $processedTasks = 0;
        
        $processedTasks++;
        
        // 每处理10个任务检查一次
        if ($processedTasks % 10 === 0) {
            $now = time();
            if ($now - $lastCheck < 1) {
                // 处理速度过快，需要暂停
                sleep(1);
            }
            $lastCheck = $now;
            
            // 检查系统负载
            $load = sys_getloadavg();
            if ($load[0] > Helper::options()->plugin('AIBaTgrMX')->maxLoad) {
                return true;
            }
        }
        
        return false;
    }

    private static function processTask($task)
    {
        switch ($task['type']) {
            case 'summary':
                return self::generateSummary($task['content_id']);
            case 'seo':
                return self::optimizeSEO($task['content_id']);
            case 'tags':
                return self::generateTags($task['content_id']);
            default:
                throw new Exception('Unknown task type: ' . $task['type']);
        }
    }

    private static function handleErrors($e)
    {
        // 添加错误类型区分
        if ($e instanceof APIException) {
            // API调用错误处理
            self::log('API Error: ' . $e->getMessage(), 'error');
            return array(
                'error' => 'api_error',
                'message' => _t('API服务暂时不可用，请稍后重试')
            );
        } else if ($e instanceof ValidationException) {
            // 数据验证错误
            self::log('Validation Error: ' . $e->getMessage(), 'warning');
            return array(
                'error' => 'validation_error',
                'message' => $e->getMessage()
            );
        }
        
        // 记录未预期的错误
        self::log('Unexpected Error: ' . $e->getMessage(), 'error');
        return array(
            'error' => 'system_error',
            'message' => _t('系统错误，请联系管理员')
        );
    }

    private static function optimizeResources()
    {
        // 添加内存使用限制
        $memoryLimit = Helper::options()->plugin('AIBaTgrMX')->memoryLimit;
        if ($memoryLimit) {
            ini_set('memory_limit', $memoryLimit.'M');
        }
        
        // 添加执行时间限制
        $timeLimit = Helper::options()->plugin('AIBaTgrMX')->timeLimit;
        if ($timeLimit) {
            set_time_limit($timeLimit);
        }
        
        // 添加并发控制
        if (self::isProcessing()) {
            throw new Exception(_t('系统正忙，请稍后重试'));
        }
    }

    private static function validateSecurity($data)
    {
        // 添加输入验证
        if (!$data || !is_array($data)) {
            throw new ValidationException(_t('无效的输入数据'));
        }
        
        // XSS防护
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = htmlspecialchars($value, ENT_QUOTES);
            }
        }
        
        // CSRF防护
        if (!self::validateToken()) {
            throw new Security_Exception(_t('安全验证失败'));
        }
        
        return $data;
    }

    private static function optimizeDatabaseOperations()
    {
        // 添加事务支持
        $db = Typecho_Db::get();
        
        try {
            $db->beginTransaction();
            
            // 批量操作优化
            if (count($items) > 10) {
                foreach (array_chunk($items, 10) as $chunk) {
                    self::processBatch($chunk);
                }
            }
            
            // 添加索引检查
            self::checkIndexes();
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private static function improveCache()
    {
        // 分级缓存
        $cache = array(
            'memory' => new Memory_Cache(),
            'file' => new File_Cache(),
            'database' => new DB_Cache()
        );
        
        // 缓存策略
        $strategy = array(
            'frequently_used' => 'memory',
            'normal' => 'file',
            'rarely_used' => 'database'
        );
        
        // 缓存清理
        if (rand(1, 100) <= 5) { // 5%概率执行
            self::cleanCache();
        }
    }

    public static function processContent($content)
    {
        try {
            // 初始化资源管理器
            $resourceManager = ResourceManager::getInstance()
                ->setLimit('memory', '256M')
                ->setLimit('time', 30)
                ->setLimit('concurrent', 3);
                
            // 创建任务处理器
            $taskProcessor = new TaskProcessor($resourceManager->getLimit('concurrent'));
            
            // 添加任务
            $taskProcessor
                ->addTask('summary', $content, 2)
                ->addTask('tags', $content, 1)
                ->addTask('seo', $content, 0);
                
            // 处理任务
            $results = $taskProcessor->processTasks();
            
            // 处理结果
            $dataProcessor = new DataProcessor();
            return $dataProcessor->processBatch($results, function($result) {
                return self::formatResult($result);
            });
            
        } catch (AIException $e) {
            self::log($e->getMessage(), $e->getLevel());
            throw $e;
        } finally {
            // 释放资源
            $resourceManager->release('memory')
                ->release('time')
                ->release('concurrent');
        }
    }

    /**
     * 检查主题兼容性
     * 
     * @throws Typecho_Plugin_Exception
     */
    private static function checkThemeCompatibility()
    {
        // 获取当前主题
        $options = Helper::options();
        $theme = $options->theme;
        
        // 检查主题目录是否存在
        $themeDir = __TYPECHO_ROOT_DIR__ . __TYPECHO_THEME_DIR__ . '/' . $theme;
        if (!is_dir($themeDir)) {
            throw new Typecho_Plugin_Exception(_t('无法获取主题信息'));
        }
        
        // 检查主题的 index.php
        $indexFile = $themeDir . '/index.php';
        if (!file_exists($indexFile)) {
            throw new Typecho_Plugin_Exception(_t('主题缺少必要的 index.php 文件'));
        }
    }

    /**
     * 检查数据库兼容性
     * 
     * @throws Typecho_Plugin_Exception
     */
    private static function checkDatabaseCompatibility()
    {
        try {
            // 获取数据库连接
            $db = Typecho_Db::get();
            
            // 检查数据库类型
            $adapter = $db->getAdapterName();
            // 支持的数据库适配器类型
            $supportedAdapters = array(
                'Mysql', 'SQLite', 'Pgsql',
                'Pdo_Mysql', 'Pdo_SQLite', 'Pdo_Pgsql'
            );
            
            if (!in_array($adapter, $supportedAdapters)) {
                throw new Typecho_Plugin_Exception(_t('不支持的数据库类型：%s', $adapter));
            }
            
            // 检查数据库版本
            $version = $db->getVersion();
            switch ($adapter) {
                case 'Mysql': case 'Pdo_Mysql':
                    if (version_compare($version, '5.5.3', '<')) {
                        throw new Typecho_Plugin_Exception(_t('MySQL版本过低，需要5.5.3或更高版本'));
                    }
                    break;
                case 'SQLite': case 'Pdo_SQLite':
                    if (version_compare($version, '3.7.0', '<')) {
                        throw new Typecho_Plugin_Exception(_t('SQLite版本过低，需要3.7.0或更高版本'));
                    }
                    break;
                case 'Pgsql': case 'Pdo_Pgsql':
                    if (version_compare($version, '9.2', '<')) {
                        throw new Typecho_Plugin_Exception(_t('PostgreSQL版本过低，需要9.2或更高版本'));
                    }
                    break;
            }
            
            // 检查表前缀
            $prefix = $db->getPrefix();
            if (empty($prefix)) {
                throw new Typecho_Plugin_Exception(_t('数据库表前缀不能为空'));
            }
            
            // 检查必要的表是否存在
            $tables = array('contents', 'fields', 'options');
            foreach ($tables as $table) {
                try {
                    $exist = $db->fetchRow($db->select()->from('table.' . $table)->limit(1));
                    if ($exist === false) {
                        throw new Typecho_Plugin_Exception(_t('数据库缺少必要的表：%s', $table));
                    }
                } catch (Typecho_Db_Exception $e) {
                    throw new Typecho_Plugin_Exception(_t('数据库缺少必要的表：%s', $table));
                }
            }
            
            // 检查字符集
            if (in_array($adapter, array('Mysql', 'Pdo_Mysql'))) {
                $charset = $db->fetchRow($db->query("SHOW VARIABLES LIKE 'character_set_database'"));
                if ($charset && $charset['Value'] != 'utf8mb4') {
                    throw new Typecho_Plugin_Exception(_t('数据库字符集必须为utf8mb4'));
                }
            }
            
        } catch (Typecho_Db_Exception $e) {
            throw new Typecho_Plugin_Exception(_t('数据库检查失败：%s', $e->getMessage()));
        }
    }

    /**
     * 初始化插件选项
     * 
     * @throws Typecho_Plugin_Exception
     */
    private static function initializeOptions()
    {
        // 获取数据库连接
        $db = Typecho_Db::get();
        
        try {
            // 默认配置项
            $defaultOptions = array(
                'keyValue' => '',              // API密钥
                'maxLength' => 200,            // 摘要最大长度
                'features' => array(           // 启用的功能
                    'summary',                 // 自动摘要
                    'tags',                    // 标签生成
                    'category',                // 分类推荐
                    'seo'                      // SEO优化
                ),
                'memoryLimit' => 256,         // 内存限制（MB）
                'timeLimit' => 30,            // 执行时间限制（秒）
                'batchSize' => 10,            // 批处理大小
                'cacheExpire' => 3600,        // 缓存过期时间（秒）
                'debugMode' => false          // 调试模式
            );
            
            // 获取现有配置
            $options = Helper::options()->plugin('AIBaTgrMX');
            
            // 合并配置，保留已有的设置
            foreach ($defaultOptions as $key => $value) {
                if (!isset($options->{$key})) {
                    $db->query($db->insert('table.options')->rows(array(
                        'name' => 'plugin:AIBaTgrMX.' . $key,
                        'value' => is_array($value) ? serialize($value) : $value,
                        'user' => 0
                    )));
                }
            }
            
        } catch (Typecho_Db_Exception $e) {
            throw new Typecho_Plugin_Exception(_t('初始化配置失败：%s', $e->getMessage()));
        }
    }

    /**
     * 获取默认摘要提示词
     */
    private static function getDefaultSummaryPrompt()
    {
        return <<<EOT
你是一个专业的文章摘要生成专家。请严格按照以下要求执行：

1. 输入：完整的文章内容
2. 输出语言：{{LANGUAGE}}
3. 输出：简洁的摘要
4. 限制条件：
   - 最大长度：{{MAX_LENGTH}}字符
   - 保持关键信息密度
   - 保留原文语气和风格
   - 聚焦核心概念和发现
5. 格式：纯文本，无标记
6. 质量检查：
   - 事实准确性
   - 逻辑连贯性
   - 信息完整性
   - 语言一致性
EOT;
    }

    /**
     * 获取默认标签提示词
     */
    private static function getDefaultTagsPrompt()
    {
        return <<<EOT
你是一个语义标签系统。请执行以下标签生成协议：

1. 输入分析：
   - 扫描专业术语
   - 识别关键概念
   - 提取领域特定术语
   - 检测重复主题

2. 标签生成规则：
   - 最大标签数：{{MAX_TAGS}}
   - 标签格式：小写，无空格
   - 长度：每个标签2-30个字符
   - 分隔符：逗号(,)
   - 输出语言：{{LANGUAGE}}

3. 标签优先级：
   - 技术准确性：40%
   - 搜索相关性：30%
   - 特异性：20%
   - 流行度：10%

4. 验证标准：
   - 无重复
   - 避免宽泛/通用术语
   - 必须具有上下文相关性
   - 必须具有搜索价值
EOT;
    }

    /**
     * 获取默认分类提示词
     */
    private static function getDefaultCategoryPrompt()
    {
        return <<<EOT
你是一个专业的文章分类推荐专家。请严格按照以下规则执行：

1. 输出格式要求：
   - 只输出一个数字（分类ID/mid）
   - 禁止输出任何其他内容
   - 禁止添加任何标点符号
   - 禁止添加任何说明文字
   - 数字必须完全匹配分类列表中的mid

2. 分类选择规则：
   - 优先选择最精确的子分类
   - 如果父分类没有子分类，则选择父分类
   - 如果无法确定，使用默认分类

3. 分类列表格式说明：
   - 每个分类都包含名称和mid
   - 格式为：分类名称 (mid: 数字)
   - 子分类使用缩进表示
   - 必须使用列表中存在的mid

4. 分析维度：
   - 主题相关性（40%）：主题匹配度、关键词重合度
   - 内容类型（30%）：文章形式、写作风格
   - 目标读者（20%）：受众群体、专业程度
   - 时效性（10%）：内容时效性、更新频率

5. 处理流程：
   a) 内容分析：
      - 提取关键主题词
      - 识别专业术语
      - 确定文章类型
   
   b) 分类匹配：
      - 计算相关度得分
      - 检查父子分类关系
      - 选择最佳匹配
   
   c) 输出处理：
      - 确保只输出mid数字
      - 验证mid存在于列表中
      - 清除多余内容

6. 示例：
   分类列表示例：
   技术文章 (mid: 12)
       Python教程 (mid: 13)
       Java开发 (mid: 14)
   
   正确输出：13
   错误输出：
   - "13"
   - [13]
   - mid:13
   - Python教程
   - 选择分类13

请记住：输出必须是一个纯数字（mid），不包含任何其他字符。
EOT;
    }

    /**
     * 获取默认SEO提示词
     */
    private static function getDefaultSeoPrompt()
    {
        return <<<EOT
你是一个SEO优化引擎。请执行以下优化协议：

1. 内容分析：
   - 核心主题识别
   - 关键信息提取
   - 价值主张检测
   - 受众定位

2. 描述要求：
   - 最大长度：{{SEO_LENGTH}}字符
   - 包含主要关键词
   - 保持可读性
   - 优化点击率

3. 关键词选择标准：
   - 搜索量潜力
   - 竞争程度
   - 相关性评分
   - 用户意图匹配

4. 输出格式：
   {
     "description": "优化后的元描述",
     "keywords": "关键词1,关键词2,关键词3"
   }

5. 质量参数：
   - 关键词密度：2-3%
   - 自然语言流畅度
   - 行动导向措辞
   - 独特价值主张

6. 技术约束：
   - 有效的JSON格式
   - UTF-8编码
   - 无HTML实体
   - 正确转义
EOT;
    }
}

class ResourceManager
{
    private static $instance;
    private $resources = array();
    private $limits = array();
    
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function setLimit($resource, $limit)
    {
        $this->limits[$resource] = $limit;
        return $this;
    }
    
    public function acquire($resource, $amount = 1)
    {
        if (!isset($this->resources[$resource])) {
            $this->resources[$resource] = 0;
        }
        
        if (isset($this->limits[$resource]) && 
            $this->resources[$resource] + $amount > $this->limits[$resource]) {
            throw new ResourceException("Resource limit exceeded: {$resource}");
        }
        
        $this->resources[$resource] += $amount;
        return true;
    }
    
    public function release($resource, $amount = 1)
    {
        if (isset($this->resources[$resource])) {
            $this->resources[$resource] = max(0, $this->resources[$resource] - $amount);
        }
    }
}

class DataProcessor
{
    private $db;
    private $batchSize;
    
    public function __construct($batchSize = 100)
    {
        $this->db = Typecho_Db::get();
        $this->batchSize = $batchSize;
    }
    
    public function processBatch($items, $callback)
    {
        $results = array();
        
        foreach (array_chunk($items, $this->batchSize) as $chunk) {
            try {
                $this->db->beginTransaction();
                
                foreach ($chunk as $item) {
                    $results[] = $callback($item);
                }
                
                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
        }
        
        return $results;
    }
}