<?php
use Yaf\Registry;

class TableModel
{

    public $table;
    public $operator = ['>' => 1, '>=' => 1, '<' => 1, '<=' => 1, '!=' => 1, 'LIKE' => 1, 'NOT LIKE' => 1, 'IN' => 1, 'NOT IN' => 1];

    /**
     * 单表操作初始化
     * @param string $table
     * @return self
     */
    public function init(string $table):self
    {
        $this->table = $table;
        $redis = Registry::get('Cache')->redis;
        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        $redis->setOption(Redis::OPT_PREFIX, Registry::get('Cache')->app . ':table:');
        if ($redis->exists($this->table)) {
            $this->data = $redis->hGetAll($this->table);
        } else {
            $data = Registry::get('Db')->fetchAll('DESC ' . $this->table);
            $this->data = array_combine(array_column($data, 'Field'), $data);
            $redis->hMSet($this->table, $this->data);
        }
        return $this;
    }
    
    public function read(array $param = [])
    {
        $param += ['column' => ['id'], 'where' => null, 'offset' => null, 'limit' => null, 'order' => null, 'group' => null, 'function' => 'fetchAll'];
        
        if (array_diff_key(array_flip($param['column']), $this->data)) {
            throw new \Exception('db hack~');
        }
        
        //筛选
        $where = $parameters = [];
        if (is_array($param['where']) && $param['where']) {
            if (array_diff_key($param['where'], $this->data)) {
                throw new \Exception('db hack~');
            }
            
            foreach ($param['where'] as $k => $v) {
                if (is_array($v)) {
                    if (array_diff_key($v, $this->operator)) {
                        throw new \Exception('db hack~');
                    }
                    
                    foreach ($v as $operator => $vv) {
                        $where[] = '`'.$k.'` '.$operator.' ('.implode(',', array_fill(0, count($vv), '?')).')';
                        $parameters = array_merge($parameters, $vv);
                    }
                } else {
                    $where[] = '`'.$k.'`=?';
                    $parameters[] = $v;
                }
            }
        }
        
        $query = $where ? ' WHERE '.join(' AND ', $where) : '';
        
        //分组
        if (is_array($param['group']) && $param['group']) {
            if (array_diff_key($param['group'], $this->data)) {
                throw new \Exception('db hack~');
            }
            
            $group = [];
            foreach ($param['group'] as $k => $v) {
                $group[] = '`'.$k.'` '.(strtoupper($v) === 'ASC' ? 'ASC' : 'DESC');
            }
            $query .= ' GROUP BY '.join(',', $group);
        }
        
        //排序
        if (is_array($param['order']) && $param['order']) {
            if (array_diff_key($param['order'], $this->data)) {
                throw new \Exception('db hack~');
            }
            
            $order = [];
            foreach ($param['order'] as $k => $v) {
                $order[] = '`'.$k.'` '.(strtoupper($v) === 'ASC' ? 'ASC' : 'DESC');
            }
            $query .= ' ORDER BY '.join(',', $order);
        }
        
        //分页
        if ($param['offset'] >= 0 && $param['limit'] > 0 ) {
            $query .= ' LIMIT '.(int) $param['offset'].', '.(int) $param['limit'];
        }
        
        //动态调用DB方法
        $query = 'SELECT `'.join('`,`', $param['column']).'` FROM `'.$this->table.'`'.$query;
        
        //var_dump($query,$parameters);exit();
        return Registry::get('Db')->{$param['function']}($query, $parameters);
    }
    
    /**
     * 返回当前表结构
     * @return array
     */
    public function desc():array
    {
        return $this->data;
    }

    /**
     * 判断表中字段是否存在
     * @param string $column
     * @return bool
     */
    public function exist(string $column):bool
    {
        return isset($this->data[$column]);
    }

    /**
     * 验证字段入值合法性，如果传入$needle参数，则自动判断该参数[本身|长度|范围]是否在此规则中并一起返回
     * @param string $column
     * @param null|int|string $column
     * @return array $column
     */
    public function validate(string $column, $needle = null):array
    {
        $type = $this->data[$column]['Type'];
        $unsigned = strpos($type, 'unsigned');
        $data = ['min' => null, 'max' => null];
        switch (1) {
            case strpos($type, 'tinyint') === 0:
                $data = ['min' => -128, 'max' => 127];
                if ($unsigned) {
                    $data = ['min' => 0, 'max' => 255];
                }
                break;
            case strpos($type, 'smallint') === 0:
                $data = ['min' => -32768, 'max' => 32767];
                if ($unsigned) {
                    $data = ['min' => 0, 'max' => 65535];
                }
                break;
            case strpos($type, 'mediumint') === 0:
                $data = ['min' => -8388608, 'max' => 8388607];
                if ($unsigned) {
                    $data = ['min' => 0, 'max' => 16777215];
                }
                break;
            case strpos($type, 'int') === 0:
                $data = ['min' => -2147483648, 'max' => 2147483647];
                if ($unsigned) {
                    $data = ['min' => 0, 'max' => 4294967295];
                }
                break;
            case strpos($type, 'bigint') === 0:
                $data = ['min' => -9223372036854775808, 'max' => 9223372036854775807];
                if ($unsigned) {
                    $data = ['min' => 0, 'max' => 18446744073709551615];
                }
                break;
            case strpos($type, 'enum') === 0:
                $type = array_flip(preg_replace(['/enum\(/', '/\)/', '/\'/'], '', explode("','" ,$type)));
                $data = ['min' => 0, 'max' => count($type)];
                if ($needle !== null) {
                    $data['validate'] = isset($type[$needle]);
                }
                return $data;
                break;
            case strpos($type, 'char') !== false:
                $needle = mb_strlen($needle);
                $data = ['min' => 0, 'max' => (int) preg_replace(['/var/', '/char/', '/\(/', '/\)/'], '', $type), 'length' => $needle];
                break;
            case strpos($type, 'binary') !== false:
                $needle = strlen($needle);
                $data = ['min' => 0, 'max' => (int) preg_replace(['/var/', '/binary/', '/\(/', '/\)/'], '', $type), 'length' => $needle];
                break;
            case strpos($type, 'tinytext') === 0 || strpos($type, 'tinyblob') === 0:
                $needle = strlen($needle);
                $data = ['min' => 0, 'max' => 255, 'length' => $needle];//255B
                break;
            case strpos($type, 'text') === 0 || strpos($type, 'blob') === 0:
                $needle = strlen($needle);
                $data = ['min' => 0, 'max' => 65535, 'length' => $needle];//64K
                break;
            case strpos($type, 'mediumtext') === 0 || strpos($type, 'mediumblob') === 0:
                $needle = strlen($needle);
                $data = ['min' => 0, 'max' => 16777215, 'length' => $needle];//16M
                break;
            case strpos($type, 'longtext') === 0 || strpos($type, 'longblob') === 0:
                $needle = strlen($needle);
                $data = ['min' => 0, 'max' => 4294967295, 'length' => $needle];//4G
                break;
        }
        
        if ($needle !== null) {
            $data['validate'] = $needle >= $data['min'] && $needle <= $data['max'];
        }
        
        return $data;
    }

    /**
     * 返回某个字段的默认值
     * @param string $column
     * @return null|string
     */
    public function default(string $column):?string
    {
        return $this->data[$column]['Default'];
    }
}
