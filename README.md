# ngender-php-API
由AI辅助将observerss大佬写的ngender改成了php，支持网页访问和API
## 功能
|功能|效果|说明|
|-|-|-|
|web访问|80/443端口访问|基础的web访问|
|API|80/443端口访问|需要配置伪静态，更多信息在：https://apihelp.serveryyswys.top/8408398m0|
|防XSS注入攻击|防攻击|如果你有WAF最好开启，防XSS是AI写的，我只是实践者，不是项目开发者|
|本地历史存储|使用LocalStorage进行存储|可存储历史记录|
|分享链接|80/443端口访问，一串带明文data的链接|能发微信里就行~~bushi~~|
|自定义映射表|能够手动指定一个名字所对应的结果，优先级比任何模式都要大|这个功能是为了避免一些众所周知的问题，也能实现更正原项目中“胜男”的问题|
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
