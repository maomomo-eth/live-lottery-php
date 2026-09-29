# 现场抽签（PHP 版）

适合小型线下活动的扫码抽奖系统。管理员输入现场总人数后创建场次，系统立即生成随机参与链接和二维码；参与者扫码、输入姓名并点击开抽。

项目采用安全的 Web 根目录结构：只有 `public/` 对外开放，程序源码和抽奖数据保存在网站根目录中。

固定奖品：

- OneKey 钱包 × 1
- OKX 帽子 × 2
- 其余结果为“谢谢参与”

## 环境要求

- PHP 8.1 或更高版本
- PHP 扩展：`mbstring`、`json`、`session`
- `storage/` 目录可写
- 不需要 MySQL、SQLite、Composer 或外部 CDN

## 目录结构

```text
/www/wwwroot/cj.maomomo.com/
├── public/             # 宝塔网站目录，只开放这一层
│   ├── admin.php
│   ├── api.php
│   ├── index.php
│   └── assets/
├── src/                # 后端程序，不对外开放
├── storage/            # 抽奖数据，不对外开放
├── tests/
└── README.md
```

## 宝塔面板部署

在服务器执行：

```bash
cd /www/wwwroot
git clone https://github.com/maomomo-eth/live-lottery-php.git cj.maomomo.com
cd /www/wwwroot/cj.maomomo.com
chown -R www:www storage
chmod 770 storage
```

在宝塔面板中设置：

- 网站域名：`cj.maomomo.com`
- 网站目录：`/www/wwwroot/cj.maomomo.com/public/`
- PHP 版本：PHP 8.1 或更高版本
- 启用 HTTPS

不需要配置伪静态，也不需要创建 MySQL 数据库。抽奖数据会自动写入：

```text
/www/wwwroot/cj.maomomo.com/storage/
```

部署后访问：

```text
https://cj.maomomo.com/admin.php
```

首次进入会要求设置管理员密码。登录后填写总人数，点击“创建并允许开抽”，后台会生成随机链接和二维码。

## 更新程序

进入项目目录拉取最新代码即可；`storage/` 中的现场数据不会被 Git 覆盖：

```bash
cd /www/wwwroot/cj.maomomo.com
git pull --ff-only
chown -R www:www storage
chmod 770 storage
```

## 其他部署方式

如果不使用宝塔，也应把网站 DocumentRoot 指向项目的 `public/` 目录，并确保 `storage/` 可写：

```bash
chmod 770 storage
```

推荐全程使用 HTTPS。`storage/` 位于 Web 根目录外，数据文件本身还带有 PHP 退出头，提供额外保护。

如果网站经过反向代理，生成的二维码域名不正确，可以设置环境变量：

```bash
LOTTERY_BASE_URL=https://lottery.example.com
```

## 现场使用流程

1. 管理员打开 `admin.php`，输入总人数。
2. 点击“创建并允许开抽”。
3. 将二维码投屏，让现场人员扫码。
4. 参与者输入姓名或昵称，点击“立即开抽”。
5. 管理后台刷新页面即可查看抽奖记录，也可暂停、继续或结束本场。

## 公平性与限制

- 奖品从服务器奖池中使用加密安全随机数抽取。
- 文件独占锁保证多人同时点击时不会重复发出同一份奖品或超出总人数。
- 每台浏览器凭 Cookie 限抽一次，同名不可重复。
- 一条共享二维码无法严格证明“一台手机等于一个人”。清除 Cookie 或更换设备仍可能再次尝试，现场应结合后台姓名记录核对。若需要强身份校验，应改为每人一个独立邀请码。

## 本地验证

```bash
php tests/run.php
php -S 127.0.0.1:8080 -t public
```

然后访问 `http://127.0.0.1:8080/admin.php`。
