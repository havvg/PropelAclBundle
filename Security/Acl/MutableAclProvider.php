<?php

/**
 * This file is part of the PropelAclBundle package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propel\Bundle\PropelAclBundle\Security\Acl;

use Propel\Bundle\PropelAclBundle\Model\Acl\Entry as ModelEntry;
use Propel\Bundle\PropelAclBundle\Model\Acl\EntryQuery;
use Propel\Bundle\PropelAclBundle\Model\Acl\ObjectIdentity;
use Propel\Bundle\PropelAclBundle\Model\Acl\ObjectIdentityQuery;
use Propel\Bundle\PropelAclBundle\Model\Acl\SecurityIdentity;
use Propel\Bundle\PropelAclBundle\Security\Acl\Domain\Acl;
use Propel\Bundle\PropelAclBundle\Security\Acl\Domain\MutableAcl;
use Symfony\Component\Security\Acl\Exception\AclAlreadyExistsException;
use Symfony\Component\Security\Acl\Exception\Exception as AclException;
use Symfony\Component\Security\Acl\Model\AclInterface;
use Symfony\Component\Security\Acl\Model\AuditableEntryInterface;
use Symfony\Component\Security\Acl\Model\EntryInterface;
use Symfony\Component\Security\Acl\Model\FieldEntryInterface;
use Symfony\Component\Security\Acl\Model\MutableAclInterface;
use Symfony\Component\Security\Acl\Model\MutableAclProviderInterface;
use Symfony\Component\Security\Acl\Model\ObjectIdentityInterface;

/**
 * An implementation of the MutableAclProviderInterface using Propel ORM.
 *
 * @author Toni Uebernickel <tuebernickel@gmail.com>
 */
class MutableAclProvider extends AclProvider implements MutableAclProviderInterface
{
    /**
     * Creates a new ACL for the given object identity.
     *
     * @throws AclAlreadyExistsException When there already is an ACL for the given object identity.
     *
     * @param ObjectIdentityInterface $objectIdentity
     *
     * @return MutableAcl
     */
    public function createAcl(ObjectIdentityInterface $objectIdentity)
    {
        $entries = EntryQuery::create()->findByAclIdentity($objectIdentity, array(), $this->connection);
        if (count($entries)) {
            throw new AclAlreadyExistsException('An ACL for the given object identity already exists, find and update that one.');
        }

        $objIdentity = ObjectIdentityQuery::create()
            ->filterByAclObjectIdentity($objectIdentity, $this->connection)
            ->findOneOrCreate($this->connection)
        ;

        if ($objIdentity->isNew()) {
            // This is safe to do, it makes the ID available and does not affect changes to any ACL.
            $objIdentity->save($this->connection);
        }

        return $this->getAcl($entries, $objectIdentity, array(), null, false);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAcl(ObjectIdentityInterface $objectIdentity)
    {
        try {
            $objIdentity = ObjectIdentityQuery::create()->findOneByAclObjectIdentity($objectIdentity, $this->connection);
            if (null === $objIdentity) {
                // No object identity, no ACL, so deletion is successful (expected result is given).
                return true;
            }

            $this->connection->beginTransaction();

            // Retrieve all class and class-field ACEs, if any.
            $aces = EntryQuery::create()->findByAclIdentity($objectIdentity, array(), $this->connection);
            if (count($aces)) {
                // In case this is the last of its kind, delete the class and class-field ACEs.
                $count = ObjectIdentityQuery::create()->filterByClassId($objIdentity->getClassId())->count($this->connection);
                if (1 === $count) {
                    $aces->delete($this->connection);
                }
            }

            /*
             * If caching is enabled, retrieve the (grand-)children of this ACL.
             * Those will be removed from the cache as well, as their parents do not exist anymore.
             */
            if (null !== $this->cache) {
                $children = ObjectIdentityQuery::create()->findGrandChildren($objIdentity, $this->connection);
            }

            // This deletes all object and object-field ACEs, too.
            $objIdentity->delete($this->connection);

            $this->connection->commit();

            if (null !== $this->cache) {
                $this->cache->evictFromCacheById($objIdentity->getId());
                foreach ($children as $eachChild) {
                    $this->cache->evictFromCacheById($eachChild->getId());
                }
            }

            return true;
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            throw new AclException('An error occurred while deleting the ACL.', 1, $e);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * {@inheritdoc}
     */
    public function updateAcl(MutableAclInterface $acl)
    {
        if (!$acl instanceof MutableAcl) {
            throw new \InvalidArgumentException('The given ACL is not tracked by this provider. Please provide \Propel\Bundle\PropelAclBundle\Security\Acl\Domain\MutableAcl only.');
        }

        try {
            $modelEntries = EntryQuery::create()->findByAclIdentity($acl->getObjectIdentity(), array(), $this->connection);
            $objectIdentity = ObjectIdentityQuery::create()->findOneByAclObjectIdentity($acl->getObjectIdentity(), $this->connection);

            $this->connection->beginTransaction();

            $keepEntries = array_merge(
                $this->persistAcl($acl->getClassAces(), $objectIdentity),
                $this->persistAcl($acl->getObjectAces(), $objectIdentity, true)
            );

            foreach ($acl->getFields() as $eachField) {
                $keepEntries = array_merge($keepEntries,
                    $this->persistAcl($acl->getClassFieldAces($eachField), $objectIdentity),
                    $this->persistAcl($acl->getObjectFieldAces($eachField), $objectIdentity, true)
                );
            }

            foreach ($modelEntries as &$eachEntry) {
                if (!in_array($eachEntry->getId(), $keepEntries)) {
                    $eachEntry->delete($this->connection);
                }
            }

            if (null === $acl->getParentAcl()) {
                $objectIdentity
                    ->setParentObjectIdentityId(null)
                    ->save($this->connection)
                ;
            } else {
                $objectIdentity
                    ->setParentObjectIdentityId($acl->getParentAcl()->getId())
                    ->save($this->connection)
                ;
            }

            $this->connection->commit();

            // After successfully committing the transaction, we are good to update the cache.
            if (null !== $this->cache) {
                $this->cache->evictFromCacheById($objectIdentity->getId());
                $this->cache->putInCache($acl);
            }

            return true;
        // @codeCoverageIgnoreStart
        } catch (Exception $e) {
            $this->connection->rollBack();

            throw new AclException('An error occurred while updating the ACL.', 0, $e);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Persist the given ACEs.
     *
     * @param array          $accessControlEntries
     * @param ObjectIdentity $objectIdentity
     * @param bool           $object
     *
     * @return int[] The IDs of the persisted ACEs.
     */
    protected function persistAcl(array $accessControlEntries, ObjectIdentity $objectIdentity, $object = false)
    {
        $entries = array();

        /* @var $eachAce EntryInterface */
        foreach ($accessControlEntries as $order => $eachAce) {
            // If the given ACE has never been persisted, create a new one.
            if (null === $entry = $this->getPersistedAce($eachAce, $objectIdentity, $object)) {
                $entry = ModelEntry::fromAclEntry($eachAce);
            }

            if (in_array($entry->getId(), $entries)) {
                $entry = ModelEntry::fromAclEntry($eachAce);
            }

            // Apply possible changes from local ACE.
            $entry
                ->setAceOrder($order)
                ->setAclClass($objectIdentity->getAclClass())
                ->setMask($eachAce->getMask())
            ;

            if ($eachAce instanceof AuditableEntryInterface) {
                if (is_bool($eachAce->isAuditSuccess())) {
                    $entry->setAuditSuccess($eachAce->isAuditSuccess());
                }

                if (is_bool($eachAce->isAuditFailure())) {
                    $entry->setAuditFailure($eachAce->isAuditFailure());
                }
            }

            if (true === $object) {
                $entry->setObjectIdentity($objectIdentity);
            }

            $entry->save($this->connection);

            $entries[] = $entry->getId();
        }

        return $entries;
    }

    /**
     * Retrieve the persisted model for the given ACE.
     *
     * If none is given, null is returned.
     *
     * @param EntryInterface $ace
     * @param ObjectIdentity $objectIdentity
     * @param bool           $object
     *
     * @return ModelEntry|null
     */
    protected function getPersistedAce(EntryInterface $ace, ObjectIdentity $objectIdentity, $object = false)
    {
        if (null !== $ace->getId() and null !== $entry = EntryQuery::create()->findPk($ace->getId(), $this->connection)) {
            $entry->reload(true, $this->connection);

            return $entry;
        }

        /*
         * The id is not set, but there may be an ACE in the database.
         *
         * This happens if the ACL has created new ACEs, but was not reloaded.
         * We try to retrieve one by the unique key.
         */
        $ukQuery = EntryQuery::create()
            ->filterByAclClass($objectIdentity->getAclClass($this->connection))
            ->filterBySecurityIdentity(SecurityIdentity::fromAclIdentity($ace->getSecurityIdentity(), $this->connection))
        ;

        if (true === $object) {
            $ukQuery->filterByObjectIdentity($objectIdentity);
        } else {
            $ukQuery->filterByObjectIdentityId(null, \Criteria::ISNULL);
        }

        if ($ace instanceof FieldEntryInterface) {
            $ukQuery->filterByFieldName($ace->getField());
        } else {
            $ukQuery->filterByFieldName(null, \Criteria::ISNULL);
        }

        return $ukQuery->findOne($this->connection);
    }

    /**
     * Creates an ACL for this provider.
     *
     * @param \PropelObjectCollection $collection
     * @param ObjectIdentityInterface $objectIdentity
     * @param array                   $loadedSecurityIdentities
     * @param AclInterface|null       $parentAcl
     * @param bool                    $inherited
     *
     * @return MutableAcl
     */
    protected function getAcl(\PropelObjectCollection $collection, ObjectIdentityInterface $objectIdentity, array $loadedSecurityIdentities = array(), AclInterface $parentAcl = null, $inherited = true)
    {
        return new MutableAcl($collection, $objectIdentity, $this->permissionGrantingStrategy, $loadedSecurityIdentities, $parentAcl, $inherited, $this->connection);
    }
}
