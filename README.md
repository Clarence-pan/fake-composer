# fake-composer
一个仿造的composer，谨以此献给饱受大局域网摧残的国人。

众所周知，github.com和packagist.org都是在国外，访问的速度由于你懂得的原因十分缓慢。虽然国内有大牛搭建了[镜像](http://pkg.phpcomposer.com/)，但是镜像毕竟不是实时刷新的，有的时候某个包刚刚提交或刚刚更新，而你又十分想用这个包怎么办呢？fake-composer提供了一种简洁方便的方式去安装对应的包。

# 使用方法
## 1. 安装composer包：

```sh
cd /path/to/your/project

# 可以从已经下载好的包来安装
php fake-composer.php require /path/to/your/downloaded/package.zip

# 也可以从网上的下载链接来安装
php fake-composer.php require https://github.com/somebody/somepackage/release/v1.1.zip
```

注：

1. 目前只支持zip压缩包
2. 仅对linux环境进行了测试，暂不支持Windows
3. 目前不会自动安装依赖，请使用check命令检查依赖并安装

## 2. 检查所有依赖关系：

```sh
cd /path/to/your/project
php fake-composer.php check
```

该命令会打印出所有被依赖的包，以及是哪些包依赖了它们。如果有缺少的依赖或冲突的依赖，会在底部显示:

```
================================================================================
Warnning: Missing dependences:
================================================================================
Array
(
    [doctrine/inflector] => Array
        (
            [illuminate/support] => ~1.0
            [doctrine/common] => 1.*
        )
)
================================================================================
Warnning: Conflict dependences:
================================================================================
Array
(
    [phpspec/prophecy] => Array
        (
            [phpunit/phpunit] => ^1.3.1
            [**installed**] => v1.1.0
        )

)
```

如果所有依赖都没问题则会提示：

```
Great! No missing dependences.
Great! No conflict dependences.
```
