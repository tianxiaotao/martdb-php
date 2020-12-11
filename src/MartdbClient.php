<?php
namespace Martdb;


use Thrift\Transport\TSocket;
use Thrift\Transport\TFramedTransport;
use Thrift\Protocol\TBinaryProtocol;
use martdb\TList;
use martdb\CodedOutputStream;
use martdb\TMap;
use martdb\MartdbRequest;
use Thrift\Exception\TException;

/**
 * Class MartdbClient
 *
 */
class MartdbClient
{
    
    /**
     * Martdb -服务
     * @var unknown
     */
    private $_client;
    
    
    /**
     * 
     * @author	 田小涛
     * @datetime 2020年12月11日 上午11:53:28
     * @comment	
     *
     */
    public function __construct( $client, $port )
    {
        
        $socket = new TSocket( $client, $port );
        $transport = new TFramedTransport( $socket );
        $protocol = new TBinaryProtocol( $transport );
        
        $this->_initClient( $transport, $protocol );
    }
    
    
    /**
     * 初始化 —— Martdb 服务
     * @author	 田小涛
     * @datetime 2020年12月11日 上午11:56:18
     * @comment	
     * 
     * @param unknown $transport
     * @param unknown $protocol
     */
    private function _initClient( $transport, $protocol )
    {
        try {
            $transport->open();
            $this->_client = new \martdb\MartdbServiceClient( $protocol );
        } catch ( Exception $e ) {
            throw new \Exception( $e );
        } catch ( TException $tx ) {
            throw new TException( $tx );
        } finally {
            $transport->close();
        }
        
        return true;
    }
    
    
    
    
    /**
     * 获取 Martdb 服务
     * @author	 田小涛
     * @datetime 2020年12月11日 下午1:36:55
     * @comment	
     * 
     * @return \martdb\MartdbServiceClient
     */
    public function getClient()
    {
        return $this->_client;
    }
    
    
    
    
    /**
     * 将参数转换为字节数组
     * @author	 田小涛
     * @datetime 2020年12月11日 下午1:42:06
     * @comment	
     * 
     * @param unknown $list
     * @param string $returnBytes
     * @return array
     */
    public function toBytes( $list, $returnBytes = false )
    {
        if ( is_null( $list ) )
        {
            $list = new TList();
        }
        
        $out = new CodedOutputStream();
        $out->writeCollection( $list );
        $out->flush();
        
        return $returnBytes ? $out->getOutput() : $out->output();
    }
    
    
    
    /**
     * 将参数转换为对象
     * @author	 田小涛
     * @datetime 2020年12月11日 下午1:42:25
     * @comment	
     * 
     * @param unknown $buf
     * @return \Martdb\TMap|\martdb\TMap
     */
    public function toObject( $buf ) 
    {
        if ( is_null( $buf ) ) 
        {
            return new TMap();
        }
        
        $input = new CodedInputStream( $buf );
        
        return $input->readMap();
    }
    
    
    
    /**
     * 获取当前系统时间的毫秒数
     * @author	 田小涛
     * @datetime 2020年12月11日 下午1:48:25
     * @comment	
     * 
     * @return number
     */
    public function currentTimeMillis()
    {
        list($msec, $sec) = explode(' ', microtime());
        $msectime =  (float)sprintf('%.0f', (floatval($msec) + floatval($sec)) * 1000);
        
        return $msectime;
    }
    
    
    
    
    
    /**
     * 获取请求句柄
     * @author	 田小涛
     * @datetime 2020年12月11日 下午2:19:47
     * @comment	
     * 
     * @param unknown $service
     * @return \martdb\MartdbRequest
     */
    public function requestHandle( $tag = false )
    {
        
        $arrParams[ 'id' ]      = uniqid( md5(microtime(true)), true );
        $arrParams[ 'sender' ]  = config( 'app.url' );
        $arrParams[ 'time' ]    = $this->currentTimeMillis();
        $arrParams[ 'tag' ]     = isset( $tag )?$tag:config( 'app.shorter' );
        
        return new MartdbRequest( $arrParams );
    }
    
    
    
    
    
    
    /**
     * 执行查询请求
     * @author	 田小涛
     * @datetime 2020年12月11日 下午2:24:25
     * @comment	
     * 
     * @param MartdbRequest $request
     * @throws \Exception
     * @return unknown|boolean
     */
    public function execute( MartdbRequest $request )
    {
        
        try {
            $response = $client->execute( $request );
            return $response;
        } catch ( Exception $e ) {
            
            throw new \Exception( 'Error during operation: ' .$e->getMessage() );
        }
        
        return true;
    }
    
    
}

