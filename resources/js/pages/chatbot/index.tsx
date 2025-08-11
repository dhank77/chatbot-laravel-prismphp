import React, { useState, useRef, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface Message {
  role: 'user' | 'assistant';
  content: string;
}

export default function Index(): React.ReactElement {
  const [messages, setMessages] = useState<Message[]>([]);
  const [input, setInput] = useState<string>('');
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [isThinking, setIsThinking] = useState<boolean>(false);
  const [streamingMessage, setStreamingMessage] = useState<string>('');
  const messagesEndRef = useRef<HTMLDivElement>(null);

  // Scroll to bottom when messages change
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, streamingMessage]);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>): Promise<void> => {
    e.preventDefault();
    if (!input.trim() || isLoading) return;

    // Add user message
    const userMessage = input.trim();
    setMessages((prev: Message[]) => [...prev, { role: 'user', content: userMessage }]);
    setInput('');
    setIsLoading(true);
    setIsThinking(true);
    setStreamingMessage('');

    try {
      // Make streaming request
      const response = await fetch('/chatbot', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'text/event-stream',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ message: userMessage }),
      });

      const reader = response.body?.getReader();
      if (!reader) throw new Error('Response body is null');

      // Process the stream
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        
        // Convert the chunk to text and append to streaming message
        const chunk = new TextDecoder().decode(value);
        setStreamingMessage((prev: string) => prev + chunk);
      }

      // When stream is complete, add the full message to the messages array
      // Use the latest streamingMessage from the state update closure to ensure we have the complete message
      setMessages((prev: Message[]) => [...prev, { role: 'assistant', content: streamingMessage }]);
      // Only reset streaming message after we've added it to messages
      setStreamingMessage('');
    } catch (error) {
      console.error('Error:', error);
      setMessages((prev: Message[]) => [...prev, { role: 'assistant', content: 'Maaf, terjadi kesalahan saat memproses pesan Anda.' }]);
    } finally {
      setIsLoading(false);
      setIsThinking(false);
    }
  };

  return (
    <div className="flex flex-col h-screen max-h-screen">
      <div className="p-4 border-b">
        <h1 className="text-xl font-bold">Chatbot Restoran</h1>
      </div>
      
      <div className="flex-1 overflow-y-auto p-4 space-y-4">
        {messages.length === 0 && (
          <div className="text-center text-gray-500 my-8">
            <p>Selamat datang di Chatbot Restoran!</p>
            <p>Silakan tanyakan tentang menu kami.</p>
          </div>
        )}
        
        {messages.map((message: Message, index: number) => (
          <div key={index} className={`flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}>
            <div 
              className={`max-w-[80%] rounded-lg p-3 ${message.role === 'user' 
                ? 'bg-primary text-primary-foreground' 
                : 'bg-secondary text-secondary-foreground'}`}
            >
              {message.content}
            </div>
          </div>
        ))}
        
        {isThinking && (
          <div className="flex justify-start">
            <div className="max-w-[80%] rounded-lg p-3 bg-secondary text-secondary-foreground">
              <div className="flex items-center">
                <span>Sedang berpikir</span>
                <span className="ml-2 flex space-x-1">
                  <span className="animate-bounce h-1.5 w-1.5 bg-secondary-foreground rounded-full delay-100"></span>
                  <span className="animate-bounce h-1.5 w-1.5 bg-secondary-foreground rounded-full delay-200"></span>
                  <span className="animate-bounce h-1.5 w-1.5 bg-secondary-foreground rounded-full delay-300"></span>
                </span>
              </div>
            </div>
          </div>
        )}
        
        {streamingMessage && !isThinking && (
          <div className="flex justify-start">
            <div className="max-w-[80%] rounded-lg p-3 bg-secondary text-secondary-foreground">
              {streamingMessage}
            </div>
          </div>
        )}
        
        <div ref={messagesEndRef} />
      </div>
      
      <div className="p-4 border-t">
        <form onSubmit={handleSubmit} className="flex gap-2">
          <Input
            value={input}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) => setInput(e.target.value)}
            placeholder="Tanyakan tentang menu kami..."
            disabled={isLoading}
            className="flex-1"
          />
          <Button type="submit" disabled={isLoading}>
            {isLoading ? 'Mengirim...' : 'Kirim'}
          </Button>
        </form>
      </div>
    </div>
  );
}
