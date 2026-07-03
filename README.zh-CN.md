# MAD Event Mailer / MAD 活动邮件系统

[English README](./README.md)

MAD Event Mailer 是一个用于 WordPress 的活动通知邮件插件，适合比赛、活动、投稿审核、成绩通知、赛程通知、结果通知等场景。

插件支持 SMTP 发信、HTML 邮件模板、模板变量、CSV 收件人导入/导出、活动订阅列表、短代码前台订阅表单、批量发送、定时发送和中英文界面语言包。

## 项目信息

- **插件名称：** MAD Event Mailer
- **作者：** [MAD Producer Studio](https://github.com/MAD-Producer)
- **许可证：** GPL v2
- **Text Domain：** `mad-event-mailer`
- **当前版本：** 2.2.1
- **短代码：** `[mad_email_register]`

## 主要功能

### SMTP 发信

可以在后台配置第三方 SMTP 服务，包括：

- SMTP 地址
- SSL/TLS 协议
- 端口
- 邮箱账号
- 邮箱密码
- 发件邮箱
- 发件人姓名
- 回复地址
- 每批发送数量

适合使用独立活动邮箱发送通知邮件。

### HTML 邮件模板

插件支持可复用的 HTML 邮件模板：

- 默认中文模板
- 默认英文模板
- 上传自定义 HTML 模板
- 直接粘贴 HTML
- 后台预览模板
- 基于通用模板快速创建新模板

默认通用模板会被锁定，不可删除。

### 模板变量

变量统一使用双大括号格式：

```text
{{变量名}}
```

常用内置变量：

| 变量 | 含义 |
| --- | --- |
| `{{title}}` / `{{title1}}` | 邮件标题 |
| `{{name}}` / `{{name1}}` | 收件人姓名 |
| `{{email}}` | 收件人邮箱 |
| `{{message}}` / `{{message1}}` | 正文内容插槽 |
| `{{unsubscribe_url}}` | 订阅管理页面链接 |

正文里也可以写自定义变量，例如：

```text
你的分数是 {{score}}
你的排名是 {{rank}}
评语：{{comment}}
```

如果 CSV 中包含 `score`、`rank`、`comment` 这些列，每个收件人就会收到自己对应的内容。

### 正文插槽编辑

通用 HTML 模板可以保持不变，只把 `{{message1}}` 当作正文插槽。

例如模板里写：

```html
<div class="personal-message">{{message1}}</div>
```

发送邮件时，只需要在后台富文本编辑器里编辑正文内容，不需要每次都修改完整 HTML 模板。

### CSV 收件人模板导出

插件可以根据当前模板和正文中使用的变量，导出对应的 CSV 模板。

示例：

```csv
email,name,events,score,rank,comment
john@example.com,John,IFT IC #6,95,2,Good work
```

基本字段：

- `email`
- `name`

可选字段：

- `events`
- 正文或模板中使用的自定义变量

### 活动订阅列表

后台可以创建和删除活动分类。用户可以选择自己想接收哪些活动通知。

适合：

- 比赛通知
- 投稿审核结果
- 赛程变更
- 获奖结果
- 社群活动通知

### 前台订阅表单

创建一个 WordPress 页面，并加入短代码：

```text
[mad_email_register]
```

前台表单支持：

- 订阅活动通知
- 查询当前订阅状态
- 退订所有通知

订阅逻辑是增量式的：用户再次订阅时，只会增加新的活动分类，不会删除之前已经订阅的分类。

退订则表示全部退订。

### 邮件底部订阅管理按钮

插件可以自动在邮件底部追加“订阅管理 / 退订”按钮。

可以在：

- 全局 SMTP 设置
- 单次发送任务设置

中控制是否添加按钮。

按钮支持中文和英文两种语言。

### 批量发送和定时发送

插件支持分批发送，减少服务器压力。后台可以设置每批发送数量。

发送任务支持：

- 立即发送
- 定时发送
- 保存草稿
- 调用旧任务设置继续编辑

定时发送依赖 WordPress Cron，因此实际执行时间会受到站点访问量和 WP-Cron 行为影响。

### 英文语言包

插件包含英文语言包：

```text
languages/en_US.php
```

可以设置：

- 后台界面语言
- 前台订阅页语言

选项包括：

- 中文
- English
- 跟随 WordPress 站点语言

邮件内容语言由你选择的邮件模板和正文内容决定。

## 安装方式

### 通过 ZIP 安装

1. 下载插件 ZIP。
2. 进入 WordPress 后台 → 插件 → 安装插件 → 上传插件。
3. 上传 ZIP 文件。
4. 启用插件。
5. 进入后台左侧的 **MAD 邮件**。
6. 先配置 SMTP，再进行发送。

### 通过源码安装

将插件文件夹复制到：

```text
wp-content/plugins/mad-event-mailer/
```

然后在 WordPress 后台启用插件。

## 推荐配置流程

1. 配置 SMTP。
2. 新建订阅管理页面，页面内容放：

   ```text
   [mad_email_register]
   ```

3. 将该页面链接填入插件设置。
4. 创建活动分类。
5. 手动添加收件人、导入 CSV，或让用户从前台订阅。
6. 选择或创建邮件模板。
7. 编辑邮件正文。
8. 如果需要个性化变量，导出 CSV 模板并填写。
9. 先发送测试邮件。
10. 创建正式发送任务。

## 模板写法规则

变量格式：

```text
{{变量名}}
```

正确示例：

```text
{{name1}}
{{message1}}
{{score}}
{{rank}}
{{comment}}
```

不推荐：

```text
{{ score }}
{{user name}}
```

推荐通用模板结构：

```html
<h1>{{title1}}</h1>
<p>Dear {{name1}},</p>
<div>{{message1}}</div>
```

个性化正文示例：

```text
Dear {{name1}},

Your score for IFT IC #6 is {{score}}.
Your ranking is {{rank}}.

Comment:
{{comment}}
```

CSV 示例：

```csv
email,name,events,score,rank,comment
john@example.com,John,IFT IC #6,95,2,Excellent work
jane@example.com,Jane,IFT IC #6,88,5,Good structure
```

## 注意事项

- 定时发送依赖 WordPress Cron。
- 大量发送时可能受到 SMTP 服务商限制。
- 正式群发前建议先用小范围收件人测试。
- 建议为发件域名配置 SPF、DKIM、DMARC，提高送达率。
- 插件用于活动通知和社群通知，不应用于垃圾邮件或未经许可的营销邮件。

## 许可证

本项目使用 **GPL v2** 许可证。

详情见 [LICENSE](./LICENSE)。

## 作者

由 [MAD Producer Studio](https://github.com/MAD-Producer) 创建和维护。
