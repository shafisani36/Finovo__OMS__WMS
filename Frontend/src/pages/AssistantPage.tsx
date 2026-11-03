import React, { useEffect, useState } from 'react';
import { AlertTriangle, Copy, Loader2, Send, Sparkles } from 'lucide-react';
import { Alert } from '../components/common/Alert';
import omsService from '../services/omsService';
import { errorMessage } from '../utils/errors';

type Insights = Awaited<ReturnType<typeof omsService.getInsights>>['data'];

interface Turn {
  question: string;
  answer?: string;
  sources?: string[];
  error?: string;
}

const EXAMPLES = ['Where is order #1042?', 'How many orders are in each status?', 'Which products are low on stock?', 'How much SER-01 do we have?'];

/**
 * Read-only AI assistant for orders and stock, with insights worked out from the records: likely duplicate orders
 * and unusual stock events. The assistant only reads; changes are made on the usual screens.
 */
export const AssistantPage: React.FC = () => {
  const [question, setQuestion] = useState('');
  const [turns, setTurns] = useState<Turn[]>([]);
  const [asking, setAsking] = useState(false);
  const [insights, setInsights] = useState<Insights | null>(null);
  const [insightsError, setInsightsError] = useState<string | null>(null);

  useEffect(() => {
    omsService
      .getInsights()
      .then((res) => setInsights(res.data))
      .catch((err) => setInsightsError(errorMessage(err, 'The insights could not be loaded.')));
  }, []);

  const ask = async (text: string) => {
    const q = text.trim();
    if (q.length < 3 || asking) return;
    setQuestion('');
    setAsking(true);
    setTurns((list) => [...list, { question: q }]);
    try {
      const res = await omsService.askAssistant(q);
      setTurns((list) => list.map((turn, i) => (i === list.length - 1 ? { ...turn, answer: res.answer, sources: res.sources } : turn)));
    } catch (err) {
      setTurns((list) => list.map((turn, i) => (i === list.length - 1 ? { ...turn, error: errorMessage(err, 'The assistant could not answer.') } : turn)));
    } finally {
      setAsking(false);
    }
  };

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Assistant</span>
      </nav>
      <div className="page-header-row">
        <div className="page-title-section">
          <h1 className="page-title">Assistant & Insights</h1>
          <p className="page-subtitle">Ask about orders and stock. Answers come only from your company's records, and the assistant never changes anything.</p>
        </div>
      </div>

      <div className="content-card" style={{ padding: 24, marginBottom: 24 }}>
        <div className="assistant-turns">
          {turns.length === 0 && (
            <div className="assistant-examples">
              {EXAMPLES.map((example) => (
                <button key={example} type="button" className="btn-finovo-secondary" onClick={() => ask(example)}>
                  <Sparkles size={14} />
                  <span>{example}</span>
                </button>
              ))}
            </div>
          )}
          {turns.map((turn, i) => (
            <div key={i} className="assistant-turn">
              <p className="assistant-question">{turn.question}</p>
              {turn.answer !== undefined && (
                <div className="assistant-answer">
                  <p>{turn.answer}</p>
                  {turn.sources && turn.sources.length > 0 && <small>From: {turn.sources.join(', ')}</small>}
                  <button type="button" className="topbar-icon-button" aria-label="Copy the answer" onClick={() => navigator.clipboard?.writeText(turn.answer ?? '')}>
                    <Copy size={14} />
                  </button>
                </div>
              )}
              {turn.error && <Alert type="error" message={turn.error} />}
              {turn.answer === undefined && !turn.error && <Loader2 size={16} className="animate-spin" />}
            </div>
          ))}
        </div>
        <form
          className="assistant-form"
          onSubmit={(e) => {
            e.preventDefault();
            ask(question);
          }}
        >
          <input className="form-input" value={question} onChange={(e) => setQuestion(e.target.value)} placeholder="Ask about an order, a product's stock, low stock or order counts..." maxLength={1000} />
          <button type="submit" className="btn-primary" disabled={asking || question.trim().length < 3}>
            {asking ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
            <span>Ask</span>
          </button>
        </form>
      </div>

      <div className="content-card" style={{ padding: 24 }}>
        <h3 className="tab-content-header-title" style={{ marginBottom: 12, display: 'flex', alignItems: 'center', gap: 8 }}>
          <AlertTriangle size={18} /> Things to Check
        </h3>
        {insightsError && <Alert type="error" message={insightsError} />}
        {insights && insights.duplicate_orders.length === 0 && insights.anomalies.length === 0 && <p className="tab-content-header-subtitle">Nothing unusual in the last 30 days.</p>}
        <ul className="assistant-insights">
          {insights?.duplicate_orders.map((dup) => (
            <li key={dup.order_numbers.join('-')}>
              <strong>Possible duplicate order:</strong> {dup.order_numbers.join(' and ')} for {dup.customer ?? 'the same customer'}, same total {dup.total_amount}, within a day.
            </li>
          ))}
          {insights?.anomalies.map((anomaly, i) => (
            <li key={`${anomaly.type}-${i}`}>
              <strong>{anomaly.type === 'negative_stock' ? `Stock below zero (${anomaly.sku})` : `Unusual adjustment (${anomaly.reference})`}:</strong> {anomaly.detail}
              {anomaly.warehouse ? ` Warehouse: ${anomaly.warehouse}.` : ''}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
};

export default AssistantPage;
