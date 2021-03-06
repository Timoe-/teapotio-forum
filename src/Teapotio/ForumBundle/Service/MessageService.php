<?php

/**
 * Copyright (c) Thomas Potaire
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @category   Teapotio
 * @package    ForumBundle
 * @author     Thomas Potaire
 */

namespace Teapotio\ForumBundle\Service;

use Teapotio\ForumBundle\Entity\Message;

use Teapotio\Base\ForumBundle\Entity\MessageInterface;
use Teapotio\Base\ForumBundle\Service\MessageServiceInterface;
use Teapotio\Base\ForumBundle\Service\MessageService as BaseMessageService;

use Symfony\Component\Security\Core\User\UserInterface;

class MessageService extends BaseMessageService implements MessageServiceInterface
{
    protected $regexBodyReplyInput =
        '/(<a class="wysiwyg-reply wysiwyg-reply-([0-9]+)"( href=".*")>)([\@\-\_\w]*)(<\/a>)/s';
    protected $regexBodyQuoteInput =
        '/(<div class="wysiwyg-block wysiwyg-quote wysiwyg-quote-([0-9]+)">)(.*)(<\/div>)/s';

    protected $regexBodyReplyId = '/wysiwyg-reply wysiwyg-reply-([0-9]+)/s';
    protected $regexBodyQuoteId = '/wysiwyg-quote wysiwyg-quote-([0-9]+)/s';

    public function createMessage()
    {
        return new Message();
    }

    /**
     * {@inherit}
     */
    public function save(MessageInterface $message)
    {
        $this->parseInputBody($message);

        return parent::save($message);
    }

    /**
     * Parse a message's body when it is being saved
     *
     * @param  MessageInterface $message
     *
     * @return Message
     */
    public function parseInputBody(MessageInterface $message)
    {
        $body = $message->getBody();
        $body = preg_replace('/<br[^<>]*>/', "\n", $body);
        $body = preg_replace('/<[^\/<>]+>/', "\n", $body);
        $body = preg_replace('/<\/.*>/', "", $body);
        $body = strip_tags($body);
        $message->setBody($body);

        $this->parseInputBodyReply($message);
        $this->parseInputBodyQuote($message);

        return $message;
    }

    /**
     * Parse the body of a list of messages when they are
     * about to be shown
     *
     * @param  array $messages
     *
     * @return array
     */
    public function parseOutputBodies($messages)
    {
        foreach ($messages as $message) {
          $body = $this
              ->container
              ->get('markdown.parser')
              ->transformMarkdown($message->getBody());
          $message->setBody($body);
        }

        $this->parseOutputBodiesReply($messages);
        $this->parseOutputBodiesQuote($messages);

        return $messages;
    }

    /**
     * Parse the body of a given list of messages and replace
     * the temporary 'reply' markup with the proper markup
     *
     * Return the list of messages
     *
     * @param  array  $messages
     *
     * @return array  unaltered list of messages
     */
    protected function parseOutputBodiesReply($messages)
    {
        $this->parseOutputBodiesGeneric(
            $messages,
            $this->regexBodyReplyId,
            function ($ids) {
                return $this->container->get('teapotio.user')->getByIds($ids);
            },
            function ($id, $entity, $body) {
                return str_replace(
                    '<a class="wysiwyg-reply wysiwyg-reply-'. $id .'"></a>',
                    $this->renderBodyReply($entity),
                    $body
                );
            }
        );
    }

    /**
     * Parse the body of a given list of messages and replace
     * the temporary 'quote' markup with the proper markup
     *
     * Return the list of messages
     *
     * @param  array  $messages
     *
     * @return array  unaltered list of messages
     */
    protected function parseOutputBodiesQuote($messages)
    {
        $this->parseOutputBodiesGeneric(
            $messages,
            $this->regexBodyQuoteId,
            function ($ids) {
                return $this->getByIds($ids);
            },
            function ($id, $entity, $body) {
                return str_replace(
                    '<div class="wysiwyg-block wysiwyg-quote wysiwyg-quote-'. $id .'"></div>',
                    $this->renderBodyQuote($entity),
                    $body
                );
            }
        );
    }

    /**
     * Generic function to replace any markup with an ID specified in it
     *
     * @param  array     $messages
     * @param  string    $regex            the regex used to find the ID in the markup
     * @param  function  $entityQueryFunc  the function must return a list of entities queried by ids
     * @param  function  $replaceFunc      the function replaces
     *
     * @return $messages
     */
    protected function parseOutputBodiesGeneric($messages, $regex, $entityQueryFunc, $replaceFunc)
    {
        $ids = array();
        $idsPerMessage = array();
        $map = array();

        // We list all the ids found in the markup
        foreach ($messages as $message) {
            $matches = array();

            // Based a given regex string
            preg_match_all($regex, $message->getBody(), $matches);

            // If there is markup then we list the IDs
            if (array_key_exists(1, $matches) === true) {
                // This is used for the query
                $ids = array_merge($ids, $matches[1]);
                // This is used when we loop through in each message so we can
                // efficiently replace the markup
                $idsPerMessage[$message->getId()] = $matches[1];
            }
        }

        // If there aren't any IDs return the list
        if (count($ids) === 0) {
            return $messages;
        }

        $entities = $entityQueryFunc($ids);

        // If none of the ids map to entities then we stop
        if (count($entities) === 0) {
            return $messages;
        }

        // list the entities mapped by their IDs
        foreach ($entities as $entity) {
            $map[$entity->getId()] = $entity;
        }

        foreach ($messages as $message) {
            // loop through the entity ids in each message
            foreach ($idsPerMessage[$message->getId()] as $id) {
                // and replace
                $message->setBody($replaceFunc($id, $map[$id], $message->getBody()));
            }
        }

        return $messages;
    }

    /**
     * Parse a message's body when it is being saved
     * This method will parse the message's body for the reply
     * markup and clean it up
     *
     * @param  MessageInterface $message
     *
     * @return Message
     */
    protected function parseInputBodyReply(MessageInterface $message)
    {
        $matches = array();
        preg_match_all($this->regexBodyReplyInput, $message->getBody(), $matches, PREG_SET_ORDER);

        $body = $message->getBody();

        foreach ($matches as $match) {
          $markup = str_replace(array($match[3], $match[4]), '', $match[0]);
          $body = str_replace($match[0], $markup, $body);
        }

        $message->setBody($body);

        return $message;
    }

    /**
     * Parse a message's body when it is being saved
     * This method will parse the message's body for the quote
     * markup and clean it up
     *
     * @param  MessageInterface $message
     *
     * @return Message
     */
    protected function parseInputBodyQuote(MessageInterface $message)
    {
        $message->setBody(preg_replace($this->regexBodyQuoteInput, '$1$4', $message->getBody()));

        // we need to make sure that the user will not quote a message outside of the topic
        $matches = array();

        preg_match_all($this->regexBodyQuoteId, $message->getBody(), $matches);

        if (empty($matches[1]) === false) {
            // Get a list of message based on the IDs present in the message's body
            $messages = $this->container->get('teapotio.forum.message')->getByIds($matches[1]);

            foreach ($messages as $m) {
                // If the message's topic is different from the submitted message's topic
                // then we strip out the illicite code
                if ($m->getTopic()->getId() !== $message->getTopic()->getId()) {
                    $message->setBody(
                        str_replace('<!-- start #'. $m->getId() .' --><!-- end -->', '', $message->getBody())
                    );
                }
            }
        }

        return $message;
    }

    /**
     * Render a message reply
     *
     * @param  UserInterface $user
     *
     * @return string
     */
    public function renderBodyReply(UserInterface $user)
    {
        return $this->container
                    ->get('templating')
                    ->render('TeapotioForumBundle:Component:wysiwyg/reply.html.twig', array('user' => $user));
    }

    /**
     * Render a message quote
     *
     * @param  MessageInterface $message
     *
     * @return string
     */
    public function renderBodyQuote(MessageInterface $message)
    {
        return $this->container
                    ->get('templating')
                    ->render(
                        'TeapotioForumBundle:Component:wysiwyg/quote.html.twig',
                        array('message' => $message, 'flag' => null)
                    );
    }

    /**
     * Run the output parsers on rendered HTML and
     * return the parsed HTML
     *
     * @param string $html
     *
     * @return string
     */
    public function parseRenderedHtml($html)
    {
        $message = $this->createMessage();
        $message->setBody($html);

        $this->parseOutputBodies(array($message));

        return $message->getBody();
    }


    public function parseBody(MessageInterface $message)
    {
        $message->setBody(html_entity_decode(htmlspecialchars_decode($message->getBody()), ENT_QUOTES));

        $regex = array("#<!--QuoteBegin-(?:[^>]*)?-->#", "#<!--quoteo\(post=([0-9]+):date=.+:name=.+\)-->#");
        $message->setBody(preg_replace($regex, '', $message->getBody()));

        $regex = "#<div class='quotetop'>(?:[\n\s]+)?QUOTE\(([^)]+)\)(?:[\n\s]+)?</div>(?:[\n\s]+)?<div class='quotemain'>(?:[\n\s]+)?<!--QuoteEBegin-->(?:[\n\s]+)?(.+)(?:[\n\s]+)?<!--QuoteEnd-->(?:[\n\s]+)?</div>(?:[\n\s]+)?<!--QuoteEEnd-->#";
        $message->setBody(preg_replace($regex, '<blockquote><header>$1</header>$2</blockquote>', $message->getBody()));
        $message->setBody(preg_replace('#\[right\](?:.*)?\[\/right\]#', '', $message->getBody()));

        $regex = "#<div class='quotetop'>(?:[\n\s]+)?Citation \((.+)\s+\@(?:[^\)]+)?\)(?:\s+)?<a href=\"index.php\?act=findpost\&pid=\d+\"><\{POST_SNAPBACK\}></a></div><div class='quotemain'>(?:[\n\s]+)?<!--quotec-->(?:[\n\s]+)?(.+)(?:[\n\s]+)?<!--QuoteEnd-->(?:[\n\s]+)?</div>(?:[\n\s]+)?<!--QuoteEEnd-->#";
        $message->setBody(preg_replace($regex, '<blockquote><header>$1</header>$2</blockquote>', $message->getBody()));

        $regex = "#<\!--emo&([^\!]+)--><img src='style_emoticons/<\#EMO_DIR\#>/\w+.\w+' border='0' style='vertical-align:middle' alt='\w+\.\w+' /><!--endemo-->#";
        $message->setBody(preg_replace($regex, '<span class="emo">$1</span>', $message->getBody()));

        $regex = '#<img src="style_emoticons/<\#EMO_DIR\#>/\w+.\w+" style="vertical-align:middle" emoid="([^>]+)" border="0" alt="\w+\.\w+" />#';
        $message->setBody(preg_replace($regex, '<span class="emo">$1</span>', $message->getBody()));

        $regex = '#\[img:(?:.+)\](.+)\[/img:(?:.+)\]#s';
        $message->setBody(preg_replace($regex, '<br /><img src="$1" />', $message->getBody()));

        $regex = '#\[size=(?:.+)\](.+)\[/size:(?:.+)\]#sU';
        $message->setBody(preg_replace($regex, '$1', $message->getBody()));

        $regex = '#\[color=(?:.+)\](.+)\[/color:(?:.+)\]#sU';
        $message->setBody(preg_replace($regex, '$1', $message->getBody()));

        $regex = '#\[code:(?:.+)\]#sU';
        $message->setBody(preg_replace($regex, '<blockquote>', $message->getBody()));

        $regex = '#\[/code:(?:.+)\]#sU';
        $message->setBody(preg_replace($regex, '</blockquote>', $message->getBody()));

        $regex = '#\[u:(?:[a-zA-Z0-9]+)\](.+)\[/u:(?:[a-zA-Z0-9]+)\]#sU';
        $message->setBody(preg_replace($regex, '<u>$1</u>', $message->getBody()));

        $regex = '#\[b:(?:[a-zA-Z0-9]+)\](.+)\[/b:(?:[a-zA-Z0-9]+)\]#sU';
        $message->setBody(preg_replace($regex, '<b>$1</b>', $message->getBody()));

        $regex = '#\[i:(?:[a-zA-Z0-9]+)\](.+)\[/i:(?:[a-zA-Z0-9]+)\]#sU';
        $message->setBody(preg_replace($regex, '<i>$1</i>', $message->getBody()));

        $matches = array();
        $regex = '#\[url=(?:.+)webheberg\.com/forum/viewtopic\.php\?t=(.+)\](.+)\[/url\]#sU';
        preg_match_all($regex, $message->getBody(), $matches);
        if (isset($matches[1][0])) {
            $topic = $this->container->get('doctrine')->getManager()
                          ->getRepository('TeapotioForumBundle:Topic')->findOneByLegacyId($matches[1]);
            $url = $this->container->get('teapotio.forum')->forumPath('ForumListMessagesByTopic', $topic);
            $message->setBody(preg_replace($regex, '<a href="'. $url .'">$2</a>', $message->getBody()));
        }

        $regex = '#\[url\](.+)\[/url\]#sU';
        $message->setBody(preg_replace($regex, '<a href="$1">$1</a>', $message->getBody()));

        $regex = '#\[url=(.+)\](.+)\[/url\]#sU';
        $message->setBody(preg_replace($regex, '<a href="$1">$2</a>', $message->getBody()));

        return $message;
    }
}
