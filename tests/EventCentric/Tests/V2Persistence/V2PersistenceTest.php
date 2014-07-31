<?php

namespace EventCentric\Tests\V2Persistence;

use EventCentric\Contracts\Contract;
use EventCentric\EventStore\EventId;
use EventCentric\Tests\Fixtures\OrderId;
use EventCentric\Tests\Fixtures\PaymentWasMade;
use EventCentric\V2EventStore\CommittedEvent;
use EventCentric\V2EventStore\PendingEvent;
use EventCentric\V2Persistence\Bucket;
use EventCentric\V2Persistence\V2Persistence;

abstract class V2PersistenceTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var V2Persistence
     */
    private $persistence;

    private $eventContract;
    private $orderContract;
    private $aStreamId;
    private $amazonBucket;
    private $ebayBucket;
    private $invoiceContract;
    private $otherStreamId;
    private $pendingEvent1;
    private $pendingEvent2;
    private $pendingEvent3;
    private $pendingEvent4;
    private $pendingEvent5;

    /**
     * @return V2Persistence
     */
    abstract protected function getPersistence();

    protected function setUp()
    {
        parent::setUp();

        $this->amazonBucket = new Bucket('amazon');
        $this->ebayBucket = new Bucket('ebay');
        $this->orderContract = Contract::with('My.Order');
        $this->invoiceContract = Contract::with('My.Invoice');
        $this->aStreamId = OrderId::generate();
        $this->otherStreamId = OrderId::generate();
        $this->eventContract = Contract::canonicalFrom(PaymentWasMade::class);

        $this->pendingEvent1 = new PendingEvent(
            EventId::generate(),
            $this->amazonBucket,
            $this->orderContract,
            $this->aStreamId,
            $this->eventContract,
            '{"my":"payload"}'
        );

        $this->pendingEvent2 = new PendingEvent(
            EventId::generate(),
            $this->ebayBucket,
            $this->orderContract,
            $this->aStreamId,
            $this->eventContract,
            '{"my":"payload"}'
        );


        $this->pendingEvent3 = new PendingEvent(
            EventId::generate(),
            $this->amazonBucket,
            $this->invoiceContract,
            $this->aStreamId,
            $this->eventContract,
            '{"my":"payload"}'
        );

        $this->pendingEvent4 = new PendingEvent(
            EventId::generate(),
            $this->amazonBucket,
            $this->orderContract,
            $this->otherStreamId,
            $this->eventContract,
            '{"my":"payload"}'
        );

        $this->pendingEvent5 = new PendingEvent(
            EventId::generate(),
            $this->amazonBucket,
            $this->orderContract,
            $this->aStreamId,
            $this->eventContract,
            '{"my":"payload"}'
        );

        $this->persistence = $this->getPersistence();
    }

    /**
     * @test
     */
    public function it_should_commit_and_fetch_event_an_event()
    {
        $this->given_events_are_committed_individually();

        $committedEvents = $this->persistence->fetchFromStream($this->amazonBucket, $this->orderContract, $this->aStreamId);

        $this->assertCount(1, $committedEvents);
        $this->assertCommittedEventMatchesPendingEvent($this->pendingEvent1, $committedEvents[0]);
    }

    /**
     * @test
     */
    public function it_should_fetch_by_bucket()
    {
        $this->given_events_are_committed_individually();

        $committedEvents = $this->persistence->fetchFromStream($this->ebayBucket, $this->orderContract, $this->aStreamId);

        $this->assertCount(1, $committedEvents);
        $this->assertCommittedEventMatchesPendingEvent($this->pendingEvent2, $committedEvents[0]);
    }

    /**
     * @test
     */
    public function it_should_fetch_by_stream_contract()
    {
        $this->given_events_are_committed_individually();

        $committedEvents = $this->persistence->fetchFromStream($this->amazonBucket, $this->invoiceContract, $this->aStreamId);

        $this->assertCount(1, $committedEvents);
        $this->assertCommittedEventMatchesPendingEvent($this->pendingEvent3, $committedEvents[0]);
    }

    /**
     * @test
     */
    public function it_should_fetch_by_stream_id()
    {
        $this->given_events_are_committed_individually();

        $committedEvents = $this->persistence->fetchFromStream($this->amazonBucket, $this->orderContract, $this->otherStreamId);

        $this->assertCount(1, $committedEvents);
        $this->assertCommittedEventMatchesPendingEvent($this->pendingEvent4, $committedEvents[0]);
    }

    /**
     * @test
     */
    public function it_should_give_the_same_commitId_to_events_committed_together()
    {
        $this->given_events_are_committed_together();

        $committedEvents = $this->persistence->fetchAll();
        $this->assertCount(4, $committedEvents);
        $this->assertCommittedEventMatchesPendingEvent($this->pendingEvent1, $committedEvents[0]);
        $this->assertCommittedEventMatchesPendingEvent($this->pendingEvent2, $committedEvents[1]);
        $this->assertCommittedEventMatchesPendingEvent($this->pendingEvent3, $committedEvents[2]);
        $this->assertCommittedEventMatchesPendingEvent($this->pendingEvent4, $committedEvents[3]);
    }

    /**
     * @test
     */
    public function it_should_give_incremental_commitSequences()
    {
        $this->given_two_commits();

        $committedEvents = $this->persistence->fetchAll();
        $this->assertEquals(1, $committedEvents[0]->getCommitSequence());
        $this->assertEquals(2, $committedEvents[1]->getCommitSequence());
        $this->assertEquals(3, $committedEvents[2]->getCommitSequence());
        $this->assertEquals(1, $committedEvents[3]->getCommitSequence());
    }

    /**
     * @test
     */
    public function it_should_give_incremental_checkpointNumbers()
    {
        $this->given_two_commits();

        $committedEvents = $this->persistence->fetchAll();

        // We use relative numbers because we can't guarantee the starting point, eg when using autoincrement in MySQL
        $checkpointNumber = $committedEvents[0]->getCheckpointNumber();
        $this->assertEquals(++$checkpointNumber, $committedEvents[1]->getCheckpointNumber());
        $this->assertEquals(++$checkpointNumber, $committedEvents[2]->getCheckpointNumber());
        $this->assertEquals(++$checkpointNumber, $committedEvents[3]->getCheckpointNumber());
    }

    /**
     * @test
     */
    public function it_should_give_incremental_streamRevisions_within_a_single_stream()
    {
        $this->given_events_are_committed_together();
        $this->given_event_is_committed_in_existing_stream();

        $committedEvents = $this->persistence->fetchFromStream($this->amazonBucket, $this->orderContract, $this->aStreamId);

        $this->assertCount(2, $committedEvents);
        $this->assertEquals(1, $committedEvents[0]->getStreamRevision());
        $this->assertEquals(2, $committedEvents[1]->getStreamRevision());

        $committedEvents = $this->persistence->fetchFromStream($this->ebayBucket, $this->orderContract, $this->aStreamId);
        $this->assertCount(1, $committedEvents);
        $this->assertEquals(1, $committedEvents[0]->getStreamRevision());
    }

    private function assertCommittedEventMatchesPendingEvent(PendingEvent $pendingEvent, CommittedEvent $committedEvent)
    {
        $this->assertTrue($pendingEvent->getEventId()->equals($committedEvent->getEventId()));
        $this->assertTrue($pendingEvent->getStreamContract()->equals($committedEvent->getStreamContract()));
        $this->assertTrue($pendingEvent->getStreamId()->equals($committedEvent->getStreamId()));
        $this->assertTrue($pendingEvent->getEventContract()->equals($committedEvent->getEventContract()));
        $this->assertEquals($pendingEvent->getEventPayload(), $committedEvent->getEventPayload());
        $this->assertEquals($pendingEvent->getBucket(), $committedEvent->getBucket());

        if ($pendingEvent->hasEventMetadataContract() && $committedEvent->hasEventMetadataContract()) {
            $this->assertEquals($pendingEvent->getEventMetadataContract(), $committedEvent->getEventMetadataContract());
        }

        if ($pendingEvent->hasCausationId() && $committedEvent->hasCausationId()) {
            $this->assertEquals($pendingEvent->getCausationId(), $committedEvent->getCausationId());
        }

        if ($pendingEvent->hasCorrelationId() && $committedEvent->hasCorrelationId()) {
            $this->assertEquals($pendingEvent->getCorrelationId(), $committedEvent->getCorrelationId());
        }
    }

    private function given_events_are_committed_individually()
    {
        $this->persistence->commit($this->pendingEvent1);
        $this->persistence->commit($this->pendingEvent2);
        $this->persistence->commit($this->pendingEvent3);
        $this->persistence->commit($this->pendingEvent4);
    }

    private function given_events_are_committed_together()
    {
        $this->persistence->commitAll(
            [
                $this->pendingEvent1,
                $this->pendingEvent2,
                $this->pendingEvent3,
                $this->pendingEvent4,
            ]
        );
    }

    private function given_two_commits()
    {
        $this->persistence->commitAll([
            $this->pendingEvent1,
            $this->pendingEvent2,
            $this->pendingEvent3,
        ]);
        $this->persistence->commit(
            $this->pendingEvent4
        );
    }

    private function given_event_is_committed_in_existing_stream()
    {
        $this->persistence->commit($this->pendingEvent5);
    }
}
 