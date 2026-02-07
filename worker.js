export default {
  async fetch(request, env, ctx) {

    if (request.method !== "POST") {
      return new Response("Method not allowed", { status: 405 });
    }

    try {
      const body = await request.json();

      const { api_key, chat_id, text, parse_mode } = body;

      // بررسی API_KEY
      if (api_key !== env.API_KEY) {
        return new Response(JSON.stringify({ ok: false, error: "Unauthorized" }), { status: 401 });
      }

      // بررسی پارامترهای ضروری
      if (!chat_id || !text) {
        return new Response(JSON.stringify({ ok: false, error: "Missing parameters" }), { status: 400 });
      }

      // ارسال پیام به تلگرام
      const tgRes = await fetch(`https://api.telegram.org/bot${env.TG_TOKEN}/sendMessage`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          chat_id,
          text,
          parse_mode: parse_mode || "HTML",
          disable_web_page_preview: true,
        }),
      });

      const data = await tgRes.text();

      return new Response(data, {
        status: 200,
        headers: { "Content-Type": "application/json" }
      });

    } catch (err) {
      return new Response(JSON.stringify({ ok: false, error: err.toString() }), { status: 500 });
    }
  }
};
