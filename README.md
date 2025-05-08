# oknav3
一个导航，直接放到里面就可以使用。
（开源的，之前是准备给NAS用的，发现其他地方用着也还行。）
## 代码结构
使用php+vue+bootstrap+layui+sqlite
## 忘记密码
使用 fix_database.php文件重置
用户名admin
密码admin123
（线上我肯定改过了，你就别去瞎搞了）
## layui引用
项目里面已经有了layui，版本2.11.0
## 数据库sqlite
/date/nav.db
如果不会就去找 fix_database.php 文件，里面有表结构，也能修复之前错误的表结构，记得提前备份下，防止数据丢失
## 强调：缺少bootstrap icon完整文件
bootstrap icon下载网址：https://icons.getbootstrap.com/
由于bootstrap icon文件太大，自己去下载吧。
本文只引入了bootstrap-icons/reception-4.svg，所以不需要太大的包

或者直接去下载完整包
https://github.com/zhaosay/oknav3/releases/tag/nav

## 引入路径
    <link rel="stylesheet" href="bootstrap-icons/font/bootstrap-icons.css">
    <link rel="stylesheet" href="layui/css/layui.css">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="bootstrap-icons/reception-4.svg" type="image/svg+xml">
