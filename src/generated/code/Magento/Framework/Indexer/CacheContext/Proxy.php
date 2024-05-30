<?php
namespace Magento\Framework\Indexer\CacheContext;

/**
 * Proxy class for @see \Magento\Framework\Indexer\CacheContext
 */
class Proxy extends \Magento\Framework\Indexer\CacheContext implements \Magento\Framework\ObjectManager\NoninterceptableInterface
{
    /**
     * Object Manager instance
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager = null;

    /**
     * Proxied instance name
     *
     * @var string
     */
    protected $_instanceName = null;

    /**
     * Proxied instance
     *
     * @var \Magento\Framework\Indexer\CacheContext
     */
    protected $_subject = null;

    /**
     * Instance shareability flag
     *
     * @var bool
     */
    protected $_isShared = null;

    /**
     * Proxy constructor
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param string $instanceName
     * @param bool $shared
     */
    public function __construct(\Magento\Framework\ObjectManagerInterface $objectManager, $instanceName = '\\Magento\\Framework\\Indexer\\CacheContext', $shared = true)
    {
        $this->_objectManager = $objectManager;
        $this->_instanceName = $instanceName;
        $this->_isShared = $shared;
    }

    /**
     * @return array
     */
    public function __sleep()
    {
        return ['_subject', '_isShared', '_instanceName'];
    }

    /**
     * Retrieve ObjectManager from global scope
     */
    public function __wakeup()
    {
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    }

    /**
     * Clone proxied instance
     */
    public function __clone()
    {
        if ($this->_subject) {
            $this->_subject = clone $this->_getSubject();
        }
    }

    /**
     * Debug proxied instance
     */
    public function __debugInfo()
    {
        return ['i' => $this->_subject];
    }

    /**
     * Get proxied instance
     *
     * @return \Magento\Framework\Indexer\CacheContext
     */
    protected function _getSubject()
    {
        if (!$this->_subject) {
            $this->_subject = true === $this->_isShared
                ? $this->_objectManager->get($this->_instanceName)
                : $this->_objectManager->create($this->_instanceName);
        }
        return $this->_subject;
    }

    /**
     * {@inheritdoc}
     */
    public function registerEntities($cacheTag, $ids)
    {
        return $this->_getSubject()->registerEntities($cacheTag, $ids);
    }

    /**
     * {@inheritdoc}
     */
    public function registerTags($cacheTags)
    {
        return $this->_getSubject()->registerTags($cacheTags);
    }

    /**
     * {@inheritdoc}
     */
    public function getRegisteredEntity($cacheTag)
    {
        return $this->_getSubject()->getRegisteredEntity($cacheTag);
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentities()
    {
        return $this->_getSubject()->getIdentities();
    }

    /**
     * {@inheritdoc}
     */
    public function flush() : void
    {
        $this->_getSubject()->flush();
    }
}
