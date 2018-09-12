#!/bin/bash
basepath=$(cd `dirname $0`; pwd)
source $basepath/config.sh
function usage()
{
    echo "Usage:./upline <project_name> <file.zip> [run]"
    echo "有以下命令可执行"
    for key in $(echo ${!path[*]})
    do
        echo "- ./upline.sh $key <file.zip>"
    done
}
if [ -z "$1" -o -z "$2" ]; then 
    usage    
    exit
fi
#项目目录配置在config文件里
project_name=$1
if [ -z ${path[$project_name]} ];then
    echo '项目不存在请配置config.sh'
    usage
    exit
fi
if [ "${2##*.}" != "zip" ]; then
    echo "file文件必须是zip格式"
    exit
fi
#run=0 debug模式|run=run 执行模式
run=0
if [ -n "$3" ]; then
    run=$3
fi
des_dir=${path[$project_name]}
src_dir_root=$basepath/$project_name/src
tmp_date_name=`date "+%Y%m%d%H%M%S"`
if [ "$run" != "run" ];then
    #debug
    tmp_dir_name=${tmp_date_name}_debug
else
    tmp_dir_name=$tmp_date_name
fi
if [ ! -d $src_dir_root/$tmp_dir_name ];then
    mkdir -p $src_dir_root/$tmp_dir_name
fi

if [ ! -f "$basepath/$2" ]; then  
    echo "file:$basepath/$2 文件不存在"
    exit
fi 
echo 'unzip:'$basepath/$2 -d $src_dir_root/$tmp_dir_name
unzip $basepath/$2 -d $src_dir_root/$tmp_dir_name
echo 'unzip:成功'

src_project_name=`ls $src_dir_root/$tmp_dir_name`
src_dir=$src_dir_root/$tmp_dir_name/$src_project_name
echo 'src_dir:'$src_dir
echo 'des_dir:'$des_dir

chown -R nobody:nobody $src_dir
echo "chown:nobody:nobody 成功"
bak_dir_root=$basepath/$project_name/backup
bak_dir=$bak_dir_root/$tmp_dir_name
if [ "$run" == "run" -a ! -e $bak_dir ];then
    echo 'bak_dir:'$bak_dir
	mkdir -p $bak_dir
    echo "创建:backup备份目录成功"
fi

if [ "$run" != "run" ];then
    echo 'debug模式:不执行拷贝' 
else
    echo '正常模式:执行拷贝'
fi
find $src_dir -type f | grep -v 'aplication.log' | grep -v 'mian.php' | grep -v 'defines.php' | grep -v 'daemon_config.php' | grep -v ".txt" | grep -v 'evn.php' | grep -v 'config.sh' | grep -v 'Config.php'  | while read filename_a
do

    filename_b=`echo $filename_a | sed "s#$src_dir#$des_dir#"` 
    if [ "$run" != "run" ];then
        if [ ! -f "$filename_b" ];then
            echo "新增:"$filename_a $filename_b
        else
            echo "修改:"$filename_a $filename_b
        fi
        continue
    fi
    if [ ! -f "$filename_b" ];then
        path_name=$(dirname $filename_b)
        if [ ! -d ${path_name} ];then
            mkdir -p ${path_name}	
        fi
        echo '新增:'$filename_a $filename_b
        /bin/cp -a $filename_a $filename_b
        echo $filename_b >>$bak_dir/add.txt
    else
        echo '修改:'$filename_a $filename_b
        des_root_path=$(cd $des_dir/../; pwd)
        filename_b_relative=`echo .$filename_b | sed "s#$des_root_path##"`
        cd $des_root_path && /bin/cp -p --parents $filename_b_relative $bak_dir && cd $basepath
        /bin/cp -p $filename_a $filename_b
    fi
done
#if [ "$run" != "run" ];then
    #rm -rf $basepath/$project_name/src/${tmp_date_name}_debug
#fi
