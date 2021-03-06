<?php

namespace spec\League\Flysystem\AwsS3v3;

use Aws\Command;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\DeleteMultipleObjectsException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Config;
use League\Flysystem\AdapterInterface;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class AwsS3AdapterSpec extends ObjectBehavior
{
    private $client;
    private $bucket;

    /**
     * @param Aws\S3\S3Client $client
     */
    public function let($client)
    {
        $this->client = $client;
        $this->bucket = 'bucket';
        $this->beConstructedWith($this->client, $this->bucket);
    }

    public function it_should_retrieve_the_bucket() 
    {
        $this->getBucket()->shouldBe('bucket');
    }

    public function it_should_retrieve_the_client()
    {
        $this->getClient()->shouldBe($this->client);
    }

    public function it_is_initializable()
    {
        $this->shouldHaveType(AwsS3Adapter::class);
        $this->shouldHaveType(AdapterInterface::class);
    }

    public function it_should_write_files()
    {
        $this->make_it_write_using('write', 'contents');
    }

    public function it_should_update_files()
    {
        $this->make_it_write_using('update', 'contents');
    }

    public function it_should_write_files_streamed()
    {
        $stream = tmpfile();
        $this->make_it_write_using('writeStream', $stream);
        fclose($stream);
    }

    public function it_should_update_files_streamed()
    {
        $stream = tmpfile();
        $this->make_it_write_using('updateStream', $stream);
        fclose($stream);
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_delete_files($command)
    {
        $this->client->getCommand('deleteObject', [
            'Bucket' => $this->bucket,
            'Key' => $key = 'key.txt',
        ])->willReturn($command);

        $this->client->execute($command)->shouldBeCalled();
        $this->make_it_404_on_get_metadata($key);

        $this->delete($key)->shouldBe(true);
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_read_a_file($command)
    {
        $this->make_it_read_a_file($command, 'read', 'contents');
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_read_a_file_stream($command)
    {
        $resource = tmpfile();
        $this->make_it_read_a_file($command, 'readStream', $resource);
        fclose($resource);
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_return_when_trying_to_read_an_non_existing_file($command) {
        $this->client->getCommand('getObject', [
            'Bucket' => $this->bucket,
            'Key' => $key = 'key.txt',
        ])->willReturn($command);

        $this->client->execute($command)->willThrow(S3Exception::class);

        $this->read($key)->shouldBe(false);
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_retrieve_all_file_metadata($command)
    {
        $this->make_it_retrieve_file_metadata('getMetadata', $command);
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_retrieve_the_timestamp_of_a_file($command)
    {
        $this->make_it_retrieve_file_metadata('getTimestamp', $command);
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_retrieve_the_mimetype_of_a_file($command)
    {
        $this->make_it_retrieve_file_metadata('getMimetype', $command);
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_retrieve_the_size_of_a_file($command)
    {
        $this->make_it_retrieve_file_metadata('getSize', $command);
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_retrieve_the_metadata_to_check_if_an_object_exists($command)
    {
        $this->make_it_retrieve_file_metadata('has', $command);
    }

    /**
     * @param Aws\CommandInterface $command
     * @param Aws\CommandInterface $aclCommand
     */
    public function it_should_copy_files($command, $aclCommand)
    {
        $key = 'key.txt';
        $sourceKey = 'newkey.txt';
        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->make_it_copy_successfully($command, $key, $sourceKey, 'private');
        $this->copy($sourceKey, $key)->shouldBe(true);
    }

    /**
     * @param Aws\CommandInterface $command
     * @param Aws\CommandInterface $aclCommand
     */
    public function it_should_return_false_when_copy_fails($command, $aclCommand)
    {
        $key = 'key.txt';
        $sourceKey = 'newkey.txt';
        $this->make_it_fail_on_copy($command, $key, $sourceKey);
        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->copy($sourceKey, $key)->shouldBe(false);
    }

    public function it_should_create_directories()
    {
        $config = new Config();
        $path = 'dir/name';
        $body = '';
        $this->client->upload(
            $this->bucket,
            $path.'/',
            $body,
            'private',
            Argument::type('array')
        )->shouldBeCalled();

        $this->createDir($path, $config)->shouldBeArray();
    }

    /**
     * @param Aws\CommandInterface $command
     * @param Aws\CommandInterface $aclCommand
     */
    public function it_should_return_false_during_rename_when_copy_fails($command, $aclCommand)
    {
        $key = 'key.txt';
        $sourceKey = 'newkey.txt';
        $this->make_it_fail_on_copy($command, $key, $sourceKey);
        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->rename($sourceKey, $key)->shouldBe(false);
    }

    /**
     * @param Aws\CommandInterface $copyCommand
     * @param Aws\CommandInterface $deleteCommand
     * @param Aws\CommandInterface $aclCommand
     */
    public function it_should_copy_and_delete_during_renames($copyCommand, $deleteCommand, $aclCommand) {
        $sourceKey = 'newkey.txt';
        $key = 'key.txt';

        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->make_it_copy_successfully($copyCommand, $key, $sourceKey, 'private');
        $this->make_it_delete_successfully($deleteCommand, $sourceKey);
        $this->make_it_404_on_get_metadata($sourceKey);
        $this->rename($sourceKey, $key)->shouldBe(true);
    }

    public function it_should_list_contents()
    {
        $prefix = 'prefix';
        $iterator = new \ArrayIterator([
            ['Key' => 'prefix/filekey.txt'],
            ['Key' => 'prefix/dirname/'],
        ]);

        $this->client->getIterator('ListObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix.'/',
        ])->willReturn($iterator);

        $this->listContents($prefix)->shouldHaveCount(3);
    }

    public function it_should_catch_404s_when_fetching_metadata()
    {
        $key = 'haha.txt';
        $this->make_it_404_on_get_metadata($key);

        $this->getMetadata($key)->shouldBe(false);
    }

    public function it_should_rethrow_non_404_responses_when_fetching_metadata()
    {
        $key = 'haha.txt';
        $response = new Psr7\Response(500);
        $command = new Command('dummy');
        $exception = new S3Exception('Message', $command, [
            'response' => $response,
        ]);

        $this->client->getCommand('headObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->willReturn($command);

        $this->client->execute($command)->willThrow($exception);
        $this->shouldThrow($exception)->duringGetMetadata($key);
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_delete_directories($command)
    {
        $this->client->deleteMatchingObjects($this->bucket, 'prefix/')->willReturn(null);

        $this->deleteDir('prefix')->shouldBe(true);
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_return_false_when_deleting_a_directory_fails($command)
    {
        $this->client->deleteMatchingObjects($this->bucket, 'prefix/')
            ->willThrow(new DeleteMultipleObjectsException([], []));

        $this->deleteDir('prefix')->shouldBe(false);
    }

    /**
     * @param Aws\CommandInterface $aclCommand
     */
    public function it_should_get_the_visibility_of_a_public_file($aclCommand)
    {
        $key = 'key.txt';
        $this->make_it_retrieve_raw_visibility($aclCommand, $key, 'public');
        $this->getVisibility($key)->shouldHaveKey('visibility');
        $this->getVisibility($key)->shouldHaveValue('public');
    }

    /**
     * @param Aws\CommandInterface $aclCommand
     */
    public function it_should_get_the_visibility_of_a_private_file($aclCommand)
    {
        $key = 'key.txt';
        $this->make_it_retrieve_raw_visibility($aclCommand, $key, 'private');
        $this->getVisibility($key)->shouldHaveKey('visibility');
        $this->getVisibility($key)->shouldHaveValue('private');
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_set_the_visibility_of_a_file_to_public($command)
    {
        $this->client->getCommand('putObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => $key = 'key.txt',
            'ACL' => 'public-read',
        ])->willReturn($command);

        $this->client->execute($command)->shouldBeCalled();

        $this->setVisibility($key, 'public')->shouldHaveValue('public');
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_set_the_visibility_of_a_file_to_private($command)
    {
        $this->client->getCommand('putObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => $key = 'key.txt',
            'ACL' => 'private',
        ])->willReturn($command);

        $this->client->execute($command)->shouldBeCalled();

        $this->setVisibility($key, 'private')->shouldHaveValue('private');
    }

    /**
     * @param Aws\CommandInterface $command
     */
    public function it_should_return_false_when_failing_to_set_visibility($command)
    {
        $this->client->getCommand('putObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => $key = 'key.txt',
            'ACL' => 'private',
        ])->willReturn($command);

        $this->client->execute($command)->willThrow(S3Exception::class);

        $this->setVisibility($key, 'private')->shouldBe(false);
    }

    /**
     * @param Aws\CommandInterface $command
     * @param $key
     * @param $visibility
     */
    private function make_it_retrieve_raw_visibility($command, $key, $visibility)
    {
        $options = [
            'private' => [
                'Grants' => [],
            ],
            'public' => [
                'Grants' => [[
                    'Grantee' => ['URI' => AwsS3Adapter::PUBLIC_GRANT_URI],
                    'Permission' => 'READ',
                ]],
            ],
        ];

        $result = new Result($options[$visibility]);

        $this->client->getCommand('getObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
    }

    /**
     * @param $method
     * @param Aws\CommandInterface $command
     */
    private function make_it_retrieve_file_metadata($method, $command)
    {
        $timestamp = time();

        $result = new Result([
            'Key' => $key = 'key.txt',
            'LastModified' => date('Y-m-d H:i:s', $timestamp),
            'ContentType' => 'plain/text',
        ]);

        $this->client->getCommand('headObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
        $this->{$method}($key)->shouldBeArray();
    }

    /**
     * @param Aws\CommandInterface $command
     * @param $method
     * @param $contents
     */
    private function make_it_read_a_file($command, $method, $contents)
    {
        $key = 'key.txt';
        $stream = Psr7\stream_for($contents);
        $result = new Result([
            'Key' => $key,
            'LastModified' => $date = date('Y-m-d h:i:s'),
            'Body' => $stream,
        ]);
        $this->client->getCommand('getObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
        $this->{$method}($key)->shouldBeArray();
    }

    /**
     * @param $method
     * @param $body
     */
    private function make_it_write_using($method, $body)
    {
        $config = new Config(['visibility' => 'public', 'mimetype' => 'plain/text', 'CacheControl' => 'value']);
        $path = 'key.txt';
        $this->client->upload(
            $this->bucket,
            $path,
            $body,
            'public-read',
            Argument::type('array')
        )->shouldBeCalled();

        $this->{$method}($path, $body, $config)->shouldBeArray();
    }

    /**
     * @param Aws\CommandInterface $copyCommand
     * @param                  $key
     * @param                  $sourceKey
     */
    private function make_it_copy_successfully($copyCommand, $key, $sourceKey, $acl)
    {
        $this->client->getCommand('copyObject', [
            'Bucket'     => $this->bucket,
            'Key'        => $key,
            'CopySource' => $this->bucket.'/'.$sourceKey,
            'ACL'        => $acl,
        ])->willReturn($copyCommand);

        $this->client->execute($copyCommand)->shouldBeCalled();
    }

    /**
     * @param Aws\CommandInterface $deleteCommand
     * @param                  $sourceKey
     */
    private function make_it_delete_successfully($deleteCommand, $sourceKey)
    {
        $deleteResult = new Result(['DeleteMarker' => true]);

        $this->client->getCommand('deleteObject', [
            'Bucket' => $this->bucket,
            'Key'    => $sourceKey,
        ])->willReturn($deleteCommand);

        $this->client->execute($deleteCommand)->willReturn($deleteResult);
    }

    /**
     * @param Aws\CommandInterface $command
     * @param                  $key
     * @param                  $sourceKey
     */
    private function make_it_fail_on_copy($command, $key, $sourceKey)
    {
        $this->client->getCommand('copyObject', [
            'Bucket'     => $this->bucket,
            'Key'        => $key,
            'CopySource' => $this->bucket.'/'.$sourceKey,
            'ACL'        => 'private',
        ])->willReturn($command);

        $this->client->execute($command)->willThrow(S3Exception::class);
    }

    public function getMatchers()
    {
        return [
            'haveKey' => function ($subject, $key) {
                return array_key_exists($key, $subject);
            },
            'haveValue' => function ($subject, $value) {
                return in_array($value, $subject);
            },
        ];
    }

    /**
     * @param $key
     */
    private function make_it_404_on_get_metadata($key)
    {
        $response = new Psr7\Response(404);
        $command = new Command('dummy');
        $exception = new S3Exception('Message', $command, [
            'response' => $response,
        ]);

        $this->client->getCommand('headObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->willReturn($command);

        $this->client->execute($command)->willThrow($exception);
    }
}
