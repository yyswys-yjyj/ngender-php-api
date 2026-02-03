# ngender-php
由AI辅助将observerss大佬写的ngender改成了php，支持网页访问和API
## 部署
### 安装
部署在安装了php 7.0+并带有mbstring的服务器，异步项目
### 关于API
请开启伪静态，以nginx为例：
```nginx
if (!-e $request_filename) {
    rewrite ^(.*)$ /index.php last;
    break;
}
```
## 项目说明
该项目搬自[observerss大佬写的ngender](https://github.com/observerss/ngender)，结合AI把它改成了可网页访问、API调用的php版本
