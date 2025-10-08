<img width="1504" height="434" alt="image" src="https://github.com/user-attachments/assets/7213ef2f-2452-4fdd-96ac-ded7925d7331" />

# Pinty Monitor
*轻量化，可拓展的服务器探针系统，基于PHP+BASH，性能卓越。*

[展示](https://sense.276966.xyz) | [TG群组](https://t.me/pintyuser) | [English README](https://github.com/crozonoo/pinty/blob/main/README.md)

# 功能
- 追踪服务器状态，从CPU型号、内存大小到连接数、进程数。
- 通过Telegram机器人实现服务器掉线/上线提示。
- 交互式世界地图，让你能看清你占领世界的进度。
- 实时刷新的状态和直观的折线图。
- 利用子系统监控展示商业级系统状态。 (Coming Soon)
- 基于服务器状态自动化执行任务。 (Coming Soon)
- PHP+BASH 驱动，性能卓越，甚至支持网站托管。
- 无状态多节点监控，保证监控系统100% SLA。 (Coming Soon)
- 支持Linux，Windows，甚至是MacOS的监控, NAT 友好.
- 支持 PostgreSQL, MySQL, SQLite 作为后端数据库.

# 如何部署?
1. 把所有除update.sh之外的文件丢进你的网站服务器/托管.
2. (OPTIONAL) 启用 pdo-mysql 和 mysql 以兼容 MySQL , pdo-pgsql 和 pgsql 以兼容 PostgreSQL .
3. 用你的浏览器访问 网站地址/setup.php 以执行初次设定.
4. 在管理员面板创建监控，填入对应的数据到update.sh中并在对应服务器运行.

# 贡献者
我等着呢。

# 协议
GNU General Public License 3.0 ~~(这样就不会被公司拐跑了吧)~~
